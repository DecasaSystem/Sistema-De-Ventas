<?php

namespace App\Services;

use App\Models\NominaPago;
use App\Models\NominaPrestacionPago;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las prestaciones sociales que se pagan: la prima (dos veces al año), las
 * cesantías (al fondo), sus intereses (al trabajador) y las vacaciones.
 *
 * Lo que se DEBE no se calcula aparte con una fórmula fija: es lo que ya se
 * fue provisionando en cada pago de nómina con los conceptos configurables
 * (CostoEmpleador: "prima", "cesantias", "intereses_cesantias", "vacaciones").
 * Así, si cambia la ley o a alguien se le quita un concepto en su ficha, lo
 * que se le debe cambia solo y cuadra con lo que Finanzas vio como costo. Con
 * el sueldo de siempre da lo mismo que la fórmula de ley (sueldo + auxilio ×
 * días / 360); si el sueldo cambió, da el promedio del periodo.
 *
 *   debe = provisionado en el periodo (pagos hechos + lo que va sin pagar)
 *        − lo que ya se pagó de ese periodo (nomina_prestaciones_pagos)
 *
 * Las vacaciones se llevan además en días: 15 hábiles por cada 360 trabajados
 * (editable), menos los que ya se tomó.
 *
 * Fechas límite (editables): prima 30 de junio y 20 de diciembre; intereses
 * al trabajador el 31 de enero; cesantías al fondo el 14 de febrero.
 */
class Prestaciones
{
    public const CLAVE = 'nomina_prestaciones_config';

    public const DEFECTO = [
        'prima_limite_1'                    => '06-30',
        'prima_limite_2'                    => '12-20',
        'intereses_limite'                  => '01-31',
        'cesantias_limite'                  => '02-14',
        'dias_vacaciones_anio'              => 15,
        // Indemnización por despido sin justa causa, contrato indefinido (art. 64 CST).
        'smmlv'                             => 1750905,
        'indemnizacion_tope_smmlv'          => 10,
        'indemnizacion_primer_anio'         => 30,
        'indemnizacion_anio_adicional'      => 20,
        'indemnizacion_primer_anio_alto'    => 20,
        'indemnizacion_anio_adicional_alto' => 15,
    ];

    public const NOMBRES = [
        'prima'               => 'Prima de servicios',
        'cesantias'           => 'Cesantías',
        'intereses_cesantias' => 'Intereses de cesantías',
        'vacaciones'          => 'Vacaciones',
        'indemnizacion'       => 'Indemnización',
    ];

    private static ?array $config = null;

    public static function olvidarCache(): void
    {
        self::$config = null;
        self::$hayTabla = null;
    }

    public static function config(): array
    {
        if (self::$config !== null) return self::$config;

        $guardado = [];
        try {
            if (\App\Support\Esquema::tabla('configuracion')) {
                $v = DB::table('configuracion')->where('clave', self::CLAVE)->value('valor');
                $guardado = $v ? (json_decode($v, true) ?: []) : [];
            }
        } catch (\Throwable) {
            $guardado = [];
        }

        return self::$config = array_merge(self::DEFECTO, array_intersect_key($guardado, self::DEFECTO));
    }

    public static function guardar(array $valores): array
    {
        $cfg = array_merge(self::config(), array_intersect_key($valores, self::DEFECTO));
        DB::table('configuracion')->updateOrInsert(['clave' => self::CLAVE], ['valor' => json_encode($cfg), 'updated_at' => now()]);
        self::olvidarCache();

        return self::config();
    }

    /**
     * El periodo de una prestación que contiene una fecha: la prima por
     * semestre; cesantías e intereses por año calendario.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function periodo(string $tipo, Carbon $fecha): array
    {
        $f = CicloNomina::fecha($fecha);
        if ($tipo === 'prima') {
            return $f->month <= 6
                ? [$f->copy()->startOfYear(), $f->copy()->month(6)->endOfMonth()->startOfDay()]
                : [$f->copy()->month(7)->startOfMonth(), $f->copy()->endOfYear()->startOfDay()];
        }

        return [$f->copy()->startOfYear(), $f->copy()->endOfYear()->startOfDay()];
    }

    /** La fecha límite de pago de un periodo. */
    public static function fechaLimite(string $tipo, Carbon $finPeriodo): Carbon
    {
        $c = self::config();
        $anio = $finPeriodo->year;
        $md = match ($tipo) {
            'prima'               => $finPeriodo->month <= 6 ? $c['prima_limite_1'] : $c['prima_limite_2'],
            'intereses_cesantias' => $c['intereses_limite'],
            'cesantias'           => $c['cesantias_limite'],
            default               => '12-31',
        };
        // Intereses y cesantías del año se pagan al año siguiente.
        if (in_array($tipo, ['cesantias', 'intereses_cesantias'], true)) $anio++;

        return CicloNomina::fecha("{$anio}-{$md}");
    }

    /**
     * Lo provisionado de un concepto en un rango, por trabajador: los pagos
     * de nómina hechos (su costo congelado) más lo que va sin pagar.
     *
     * @param array $sinPagar  liquidaciones de ciclos no pagados (las de
     *                         NominaLiquidador o las de una liquidación al retiro)
     * @return array<int, array{monto: float, dias: float}>
     */
    public static function causado(string $clave, Carbon $desde, Carbon $hasta, ?int $usuarioId = null, ?array $sinPagar = null): array
    {
        $desde = CicloNomina::fecha($desde);
        $hasta = CicloNomina::fecha($hasta);
        $out = [];

        $pagos = NominaPago::with(CostoEmpleador::relacionesDePago())
            ->when($usuarioId, fn ($q) => $q->where('usuario_id', $usuarioId))
            ->whereDate('fecha_fin', '>=', $desde->toDateString())
            ->whereDate('fecha_inicio', '<=', $hasta->toDateString())
            ->get();

        foreach ($pagos as $p) {
            self::sumar($out, $p->usuario_id, $p->fecha_inicio, $p->fecha_fin, $desde, $hasta,
                CostoEmpleador::dePago($p), (float) $p->dias, $clave);
        }

        foreach ($sinPagar ?? self::sinPagar($usuarioId) as $l) {
            self::sumar($out, $l['usuario_id'], $l['fecha_inicio'], $l['fecha_corte'] ?? $l['fecha_fin'], $desde, $hasta,
                $l['costo_empleador'], (float) $l['dias'], $clave);
        }

        return $out;
    }

    /**
     * Los ciclos devengados que todavía no se pagan: los cerrados sin cobrar
     * y lo que lleva el ciclo en curso (hasta hoy).
     */
    public static function sinPagar(?int $usuarioId = null): array
    {
        $hoy = CicloNomina::hoy();
        $out = [];
        // Los mismos trabajadores para los dos recorridos: una consulta, no dos.
        $empleados = NominaLiquidador::empleadosLiquidables();
        foreach (NominaLiquidador::pendientes($hoy, $empleados) as $l) {
            if ($usuarioId && $l['usuario_id'] !== $usuarioId) continue;
            $out[] = $l + ['fecha_corte' => $l['fecha_fin']];
        }
        foreach ($empleados as $e) {
            if ($usuarioId && $e->id !== $usuarioId) continue;
            $l = NominaLiquidador::cicloActual($e, $hoy);
            if ($l['cerrado'] || $l['dias'] <= 0) continue;
            $out[] = $l + ['usuario_id' => $e->id, 'fecha_corte' => $hoy->toDateString()];
        }

        return $out;
    }

    /** Lo ya pagado de una prestación en un periodo, por trabajador. @return array<int, array{monto: float, dias: float}> */
    public static function pagado(string $tipo, Carbon $desde, Carbon $hasta, ?int $usuarioId = null): array
    {
        if (! self::hayTabla()) return [];

        return self::pagadoDeVerdad($tipo, $desde, $hasta, $usuarioId);
    }

    private static ?bool $hayTabla = null;

    /** ¿Ya corrió la migración de prestaciones? (las pruebas montan esquemas a mano) */
    public static function hayTabla(): bool
    {
        return self::$hayTabla ??= \App\Support\Esquema::tabla('nomina_prestaciones_pagos');
    }

    private static function pagadoDeVerdad(string $tipo, Carbon $desde, Carbon $hasta, ?int $usuarioId): array
    {
        $out = [];
        $filas = NominaPrestacionPago::where('tipo', $tipo)->where('estado', 'pagado')
            ->when($usuarioId, fn ($q) => $q->where('usuario_id', $usuarioId))
            ->whereDate('periodo_desde', '>=', CicloNomina::fecha($desde)->toDateString())
            ->whereDate('periodo_hasta', '<=', CicloNomina::fecha($hasta)->toDateString())
            ->get(['usuario_id', 'monto', 'dias']);
        foreach ($filas as $f) {
            $out[$f->usuario_id] ??= ['monto' => 0.0, 'dias' => 0.0];
            $out[$f->usuario_id]['monto'] += (float) $f->monto;
            $out[$f->usuario_id]['dias'] += (float) $f->dias;
        }

        return $out;
    }

    /**
     * Lo de un periodo para todos: cuánto se provisionó, cuánto se pagó y
     * cuánto se debe, persona por persona.
     */
    public static function resumen(string $tipo, Carbon $desde, Carbon $hasta): array
    {
        $causado = self::causado($tipo, $desde, $hasta);
        $pagado  = self::pagado($tipo, $desde, $hasta);
        $ids = array_unique(array_merge(array_keys($causado), array_keys($pagado)));
        $gente = Usuario::with('rolAsignado:id,nombre')->whereIn('id', $ids ?: [0])->get()->keyBy('id');

        $filas = [];
        foreach ($ids as $id) {
            $c = $causado[$id]['monto'] ?? 0;
            $p = $pagado[$id]['monto'] ?? 0;
            if (round($c) <= 0 && round($p) <= 0) continue;
            $u = $gente[$id] ?? null;
            $filas[] = [
                'usuario_id' => $id,
                'nombre'     => $u?->nombre,
                'cargo'      => $u?->rolAsignado?->nombre,
                'fondo'      => $u?->nomina_fondo_cesantias,
                'retirado'   => (bool) $u?->nomina_retiro,
                'dias'       => round($causado[$id]['dias'] ?? 0, 1),
                'causado'    => round($c),
                'pagado'     => round($p),
                'saldo'      => max(0, round($c - $p)),
            ];
        }
        usort($filas, fn ($a, $b) => strcmp((string) $a['nombre'], (string) $b['nombre']));

        return [
            'tipo'          => $tipo,
            'nombre'        => self::NOMBRES[$tipo],
            'desde'         => CicloNomina::fecha($desde)->toDateString(),
            'hasta'         => CicloNomina::fecha($hasta)->toDateString(),
            'fecha_limite'  => self::fechaLimite($tipo, CicloNomina::fecha($hasta))->toDateString(),
            'causado'       => round(array_sum(array_column($filas, 'causado'))),
            'pagado'        => round(array_sum(array_column($filas, 'pagado'))),
            'saldo'         => round(array_sum(array_column($filas, 'saldo'))),
            'trabajadores'  => $filas,
        ];
    }

    /**
     * Las vacaciones de cada uno, en días y en plata: 15 días hábiles por cada
     * 360 trabajados (editable) menos los que ya se tomó. La plata de un día
     * es el sueldo del día, sin auxilio.
     */
    public static function vacaciones(?int $usuarioId = null): array
    {
        return self::hayTabla() ? self::calcularVacaciones($usuarioId) : [];
    }

    private static function calcularVacaciones(?int $usuarioId): array
    {
        $diasAnio = (float) self::config()['dias_vacaciones_anio'];

        $trabajados = NominaPago::when($usuarioId, fn ($q) => $q->where('usuario_id', $usuarioId))
            ->selectRaw('usuario_id, SUM(dias) AS dias')->groupBy('usuario_id')->pluck('dias', 'usuario_id')->map(fn ($d) => (float) $d)->all();
        foreach (self::sinPagar($usuarioId) as $l) {
            $trabajados[$l['usuario_id']] = ($trabajados[$l['usuario_id']] ?? 0) + (float) $l['dias'];
        }

        $tomados = NominaPrestacionPago::where('tipo', 'vacaciones')->where('estado', 'pagado')
            ->when($usuarioId, fn ($q) => $q->where('usuario_id', $usuarioId))
            ->selectRaw('usuario_id, SUM(dias) AS dias, SUM(monto) AS monto')->groupBy('usuario_id')->get()->keyBy('usuario_id');

        $gente = Usuario::with(['sueldo', 'rolAsignado:id,nombre'])
            ->whereIn('id', array_keys($trabajados) ?: [0])
            ->where(fn ($q) => $q->whereNotNull('nomina_sueldo_id')->orWhereNotNull('nomina_retiro'))
            ->get();

        $filas = [];
        foreach ($gente as $u) {
            // Quien no tiene el concepto de vacaciones (prestación de servicios) no acumula.
            $tiene = collect(CostoEmpleador::conceptosDe($u, CicloNomina::hoy()))->firstWhere('clave', 'vacaciones')['aplica'] ?? true;
            $trab = $trabajados[$u->id] ?? 0;
            $causados = $tiene ? round($trab * $diasAnio / 360, 2) : 0;
            $usados = (float) ($tomados[$u->id]->dias ?? 0);
            $pendientes = max(0, round($causados - $usados, 2));
            $valorDia = $u->valorDiaEfectivo();

            $filas[] = [
                'usuario_id'      => $u->id,
                'nombre'          => $u->nombre,
                'cargo'           => $u->rolAsignado?->nombre,
                'desde'           => CicloNomina::fecha($u->nomina_desde ?? $u->created_at)->toDateString(),
                'retirado'        => (bool) $u->nomina_retiro,
                'dias_trabajados' => round($trab, 1),
                'dias_causados'   => $causados,
                'dias_tomados'    => $usados,
                'dias_pendientes' => $pendientes,
                'valor_dia'       => round($valorDia),
                'valor_pendiente' => round($pendientes * $valorDia),
                'aplica'          => (bool) $tiene,
            ];
        }
        usort($filas, fn ($a, $b) => $b['dias_pendientes'] <=> $a['dias_pendientes']);

        return $filas;
    }

    /**
     * Lo que vence en una ventana de fechas: la prima de junio o diciembre,
     * los intereses de enero, las cesantías de febrero. Para el calendario de
     * Finanzas y el flujo de caja.
     *
     * @return array<int, array{tipo: string, nombre: string, fecha: string, monto: float, desde: string, hasta: string}>
     */
    public static function vencimientos(Carbon $desde, Carbon $hasta): array
    {
        return self::hayTabla() ? self::calcularVencimientos($desde, $hasta) : [];
    }

    private static function calcularVencimientos(Carbon $desde, Carbon $hasta): array
    {
        $out = [];
        $hoy = CicloNomina::hoy();
        foreach ([$hoy->year - 1, $hoy->year, $hoy->year + 1] as $anio) {
            $candidatos = [
                ['prima', CicloNomina::fecha("{$anio}-01-01"), CicloNomina::fecha("{$anio}-06-30")],
                ['prima', CicloNomina::fecha("{$anio}-07-01"), CicloNomina::fecha("{$anio}-12-31")],
                ['intereses_cesantias', CicloNomina::fecha("{$anio}-01-01"), CicloNomina::fecha("{$anio}-12-31")],
                ['cesantias', CicloNomina::fecha("{$anio}-01-01"), CicloNomina::fecha("{$anio}-12-31")],
            ];
            foreach ($candidatos as [$tipo, $pIni, $pFin]) {
                $limite = self::fechaLimite($tipo, $pFin);
                if ($limite->lessThan($desde) || $limite->greaterThan($hasta)) continue;
                // Lo provisionado hasta hoy y lo que falta del periodo, proyectado
                // con el ritmo de lo que va (los ciclos que vienen no existen aún).
                $r = self::resumen($tipo, $pIni, $pFin);
                $corte = $hoy->lessThan($pFin) ? $hoy : $pFin;
                $transcurrido = max(1, $pIni->diffInDays($corte) + 1);
                $total = $pIni->diffInDays($pFin) + 1;
                $estimado = $hoy->lessThan($pFin);
                $monto = $estimado ? $r['saldo'] * $total / $transcurrido : $r['saldo'];
                if ($monto <= 0) continue;
                $out[] = [
                    'tipo'     => $tipo,
                    'nombre'   => self::NOMBRES[$tipo],
                    'fecha'    => $limite->toDateString(),
                    'monto'    => round($monto),
                    'estimado' => $estimado,
                    'desde'    => $pIni->toDateString(),
                    'hasta'    => $pFin->toDateString(),
                ];
            }
        }

        return $out;
    }

    /** Suma la parte de una línea del costo que cae en [desde, hasta], por días. */
    private static function sumar(array &$out, int $usuarioId, $ini, $fin, Carbon $desde, Carbon $hasta, array $costo, float $dias, string $clave): void
    {
        $ini = CicloNomina::fecha($ini);
        $fin = CicloNomina::fecha($fin);
        $a = $ini->greaterThan($desde) ? $ini : $desde;
        $b = $fin->lessThan($hasta) ? $fin : $hasta;
        if ($b->lessThan($a)) return;

        $total = $ini->diffInDays($fin) + 1;
        $fraccion = ($a->diffInDays($b) + 1) / max(1, $total);
        $monto = (float) (collect($costo['lineas'] ?? [])->firstWhere('clave', $clave)['monto'] ?? 0);

        $out[$usuarioId] ??= ['monto' => 0.0, 'dias' => 0.0];
        $out[$usuarioId]['monto'] += $monto * $fraccion;
        $out[$usuarioId]['dias'] += $dias * $fraccion;
    }
}
