<?php

namespace App\Services\Finanzas;

use App\Models\NominaPago;
use App\Models\Usuario;
use App\Services\CicloNomina;
use App\Services\CostoEmpleador;
use App\Services\NominaLiquidador;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La nómina vista desde Finanzas. Nada se recalcula por fuera de Nómina: lo
 * pagado sale de `nomina_pagos` (congelado), lo que falta de NominaLiquidador
 * y lo que pone la empresa por detrás de CostoEmpleador.
 *
 * Dos cifras distintas, y no se mezclan (docs/plan-gestion-financiera.md §3.3):
 *
 * - COSTO (estado de resultados): devengado bruto + lo que pone la empresa.
 *     bruto = sueldo + auxilio − faltas − incapacidad + bono ± ajustes
 *   El `total` del pago NO sirve: es lo que recibe la persona, ya sin su
 *   seguridad social ni las cuotas de préstamo (devolver un préstamo no es
 *   un gasto). Un ciclo que cruza de mes se reparte por días.
 * - CAJA (flujo): lo que salió el día que se pagó — el neto a la persona, su
 *   seguridad social y los aportes del empleador (los dos van a la PILA). Las
 *   prestaciones no salen ese día: se provisionan y salen en junio/diciembre
 *   (prima), febrero (cesantías) o al tomar vacaciones.
 *
 * Cada trabajador cae en un área del estado de resultados según su rol:
 * taller → producción; vendedor y conductor → ventas; el resto, administración.
 */
class FuenteNomina
{
    /**
     * @return array<string, array> [mes => bruto, aportes, prestaciones, otros, costo, por_area, caja, personas, estimado]
     */
    public static function porMes(string $desde, string $hasta, bool $incluirSinPagar = true): array
    {
        [$ini] = Periodo::limites($desde);
        [, $fin] = Periodo::limites($hasta);
        $meses = array_flip(Periodo::meses($desde, $hasta));
        $out = [];

        $pagos = NominaPago::with('trabajador.rolAsignado')
            ->whereDate('fecha_fin', '>=', $ini->toDateString())
            ->whereDate('fecha_inicio', '<=', $fin->toDateString())
            ->get();

        foreach ($pagos as $p) {
            $costo = CostoEmpleador::dePago($p);
            $bruto = (float) $p->subtotal + (float) $p->auxilio_transporte - (float) $p->descuento_faltas
                   - (float) $p->descuento_incapacidad + (float) $p->bonificacion + (float) $p->total_ajustes;
            self::sumar($out, $meses, $p->fecha_inicio, $p->fecha_fin, $bruto, $costo, self::areaDe($p->trabajador), $p->usuario_id, false);
        }

        // Caja: el día que se pagó.
        $cajaPagos = NominaPago::whereBetween('pagado_at', Periodo::rangoUtc($desde, $hasta))->get();
        foreach ($cajaPagos as $p) {
            $mes = Periodo::mesDe($p->pagado_at, true);
            if (! isset($meses[$mes])) continue;
            $costo = CostoEmpleador::dePago($p);
            $out[$mes] ??= self::vacio();
            $out[$mes]['caja'] += (float) $p->total + (float) $p->descuento_seguridad_social + (float) $costo['aportes'];
        }

        // Las prestaciones que se pagaron (Nómina → Prestaciones y liquidaciones).
        // Prima, cesantías, intereses y vacaciones ya estaban en el costo como
        // provisión de cada pago: aquí solo son caja. La indemnización sí es un
        // costo nuevo. Y las vacaciones que se tomaron "con la nómina" no
        // salieron aparte: el sueldo de esos días ya se pagó en el ciclo y ya
        // estaba provisionado, así que se resta del sueldo para no contarlo dos veces.
        if (\Illuminate\Support\Facades\Schema::hasTable('nomina_prestaciones_pagos')) {
            $prest = \App\Models\NominaPrestacionPago::with('trabajador')->where('estado', 'pagado')
                ->whereDate('fecha_pago', '>=', $ini->toDateString())->whereDate('fecha_pago', '<=', $fin->toDateString())->get();
            foreach ($prest as $p) {
                $mes = Periodo::mesDe($p->fecha_pago);
                if (! isset($meses[$mes])) continue;
                $out[$mes] ??= self::vacio();
                $monto = (float) $p->monto;
                if ($p->forma === 'con_nomina') {
                    $out[$mes]['bruto'] -= $monto;
                    $area = self::areaDe($p->trabajador);
                    $out[$mes]['por_area'][$area] = ($out[$mes]['por_area'][$area] ?? 0) - $monto;
                    continue;
                }
                $out[$mes]['caja'] += $monto;
                $out[$mes]['prestaciones_pagadas'] += $monto;
                if ($p->tipo === 'indemnizacion') {
                    $out[$mes]['indemnizaciones'] += $monto;
                    $area = self::areaDe($p->trabajador);
                    $out[$mes]['por_area'][$area] = ($out[$mes]['por_area'][$area] ?? 0) + $monto;
                }
            }
        }

        // Lo devengado que todavía no se paga: los ciclos cerrados sin cobrar
        // y lo que lleva el ciclo en curso. Va marcado como estimado.
        if ($incluirSinPagar) {
            $hoy = CicloNomina::hoy();
            $empleados = NominaLiquidador::empleadosLiquidables()->keyBy('id');

            foreach (NominaLiquidador::pendientes($hoy) as $l) {
                self::sumarLiquidacion($out, $meses, $l, $empleados[$l['usuario_id']] ?? null, $l['fecha_fin']);
            }
            foreach ($empleados as $e) {
                $l = NominaLiquidador::cicloActual($e, $hoy);
                // Si cerró hoy ya salió en pendientes.
                if ($l['cerrado'] || $l['dias'] <= 0) continue;
                self::sumarLiquidacion($out, $meses, $l + ['usuario_id' => $e->id], $e, $hoy->toDateString());
            }
        }

        foreach ($out as &$m) {
            $m['personas'] = count($m['personas']);
            $m['costo'] = round($m['bruto'] + $m['aportes'] + $m['prestaciones'] + $m['otros'] + $m['indemnizaciones']);
        }
        unset($m);

        return $out;
    }

    /**
     * La nómina de los próximos meses, con los sueldos de hoy y los
     * porcentajes que vayan a regir (si ya se cargó un cambio de ley para
     * enero, enero sale con ese). El bono se estima con el promedio de los
     * últimos tres pagos de cada uno.
     *
     * @return array{meses: array<string, array>, pagos: array<int, array>}
     *         pagos = cada ciclo futuro con su fecha de pago (para el calendario)
     */
    public static function proyeccion(Carbon $hasta): array
    {
        $hoy = CicloNomina::hoy();
        $empleados = NominaLiquidador::empleadosLiquidables();
        $bonos = self::bonoPromedio($empleados->pluck('id')->all());

        $meses = [];
        $pagos = [];
        foreach ($empleados as $e) {
            [$inicio, $fin] = CicloNomina::rango($e->periodicidad, $hoy);
            for ($i = 0; $i < 400 && $inicio->lessThanOrEqualTo($hasta); $i++) {
                // El ciclo completo, como si ya hubiera cerrado.
                $l = NominaLiquidador::liquidar($e, $inicio, $fin, $fin);
                $bono = $bonos[$e->id] ?? 0.0;
                $l['bonificacion'] = $bono;
                $l['total'] += $bono;
                $costo = CostoEmpleador::deLiquidacion($e, $l, $fin);
                $bruto = $l['subtotal'] + $l['auxilio_transporte'] - $l['descuento_faltas']
                       - $l['descuento_incapacidad'] + $bono + $l['total_ajustes'];

                $mesesTodos = array_flip(Periodo::meses(Periodo::mesDe($inicio), Periodo::mesDe($fin)));
                self::sumar($meses, $mesesTodos, $inicio, $fin, $bruto, $costo, self::areaDe($e), $e->id, true);

                $pagos[] = [
                    'usuario_id'   => $e->id,
                    'nombre'       => $e->nombre,
                    'periodicidad' => $e->periodicidad,
                    'fecha_inicio' => $inicio->toDateString(),
                    'fecha_pago'   => $fin->toDateString(),
                    'neto'         => round($l['total']),
                    'aportes'      => $costo['aportes'],
                ];

                [$inicio, $fin] = CicloNomina::siguiente($e->periodicidad, $inicio);
            }
        }

        foreach ($meses as &$m) {
            $m['personas'] = count($m['personas']);
            $m['costo'] = round($m['bruto'] + $m['aportes'] + $m['prestaciones'] + $m['otros']);
        }
        unset($m);

        return ['meses' => $meses, 'pagos' => $pagos];
    }

    /** taller → producción; vendedor/conductor → ventas; el resto → administración. */
    public static function areaDe(?Usuario $u): string
    {
        if (! $u) return 'administracion';
        // El rol lo crea cada empresa ("Lijador", "Metalero"); lo que dice qué
        // hace es su arquetipo. Se leen todos de una vez, no uno por persona.
        $arquetipo = self::arquetipos()[$u->rol_id] ?? $u->rol;

        if ($u->no_usa_programa || in_array($arquetipo, ['taller', 'despachador'], true)) return 'produccion';
        if (in_array($arquetipo, ['vendedor', 'conductor'], true)) return 'ventas';

        return 'administracion';
    }

    private static ?array $arquetipos = null;

    private static function arquetipos(): array
    {
        try {
            return self::$arquetipos ??= \Illuminate\Support\Facades\Schema::hasTable('roles')
                ? DB::table('roles')->pluck('arquetipo', 'id')->all()
                : [];
        } catch (\Throwable) {
            return self::$arquetipos = [];
        }
    }

    public static function olvidarCache(): void
    {
        self::$arquetipos = null;
    }

    private static function sumarLiquidacion(array &$out, array $meses, array $l, ?Usuario $e, string $hasta): void
    {
        $bruto = $l['subtotal'] + $l['auxilio_transporte'] - $l['descuento_faltas']
               - $l['descuento_incapacidad'] + $l['bonificacion'] + $l['total_ajustes'];
        $fin = min($l['fecha_fin'], $hasta);
        self::sumar($out, $meses, $l['fecha_inicio'], $fin, $bruto, $l['costo_empleador'], self::areaDe($e), $l['usuario_id'], true);
    }

    private static function sumar(array &$out, array $meses, $desde, $hasta, float $bruto, array $costo, string $area, int $usuarioId, bool $estimado): void
    {
        foreach (Periodo::repartir($desde, $hasta) as $mes => $fraccion) {
            if (! isset($meses[$mes])) continue;
            $out[$mes] ??= self::vacio();
            $m = &$out[$mes];
            $parteCosto = $bruto + (float) $costo['total'];
            $m['bruto']        += $bruto * $fraccion;
            $m['aportes']      += (float) $costo['aportes'] * $fraccion;
            $m['prestaciones'] += (float) $costo['prestaciones'] * $fraccion;
            $m['otros']        += (float) ($costo['otros'] ?? 0) * $fraccion;
            $m['por_area'][$area] = ($m['por_area'][$area] ?? 0) + $parteCosto * $fraccion;
            $m['personas'][$usuarioId] = true;
            $m['por_usuario'][$usuarioId] = ($m['por_usuario'][$usuarioId] ?? 0) + $parteCosto * $fraccion;
            if ($estimado) $m['estimado'] = true;
            unset($m);
        }
    }

    /** @return array<int, float> [usuario_id => bono promedio de sus últimos 3 pagos] */
    private static function bonoPromedio(array $ids): array
    {
        if (! $ids) return [];

        $out = [];
        foreach (DB::table('nomina_pagos')->whereIn('usuario_id', $ids)
                     ->orderByDesc('fecha_fin')->get(['usuario_id', 'bonificacion']) as $f) {
            $out[$f->usuario_id] ??= [];
            if (count($out[$f->usuario_id]) < 3) $out[$f->usuario_id][] = (float) $f->bonificacion;
        }

        return array_map(fn ($b) => round(array_sum($b) / max(1, count($b))), $out);
    }

    private static function vacio(): array
    {
        return [
            'bruto' => 0.0, 'aportes' => 0.0, 'prestaciones' => 0.0, 'otros' => 0.0, 'costo' => 0.0,
            'por_area' => [], 'caja' => 0.0, 'personas' => [], 'por_usuario' => [], 'estimado' => false,
            'prestaciones_pagadas' => 0.0, 'indemnizaciones' => 0.0,
        ];
    }
}
