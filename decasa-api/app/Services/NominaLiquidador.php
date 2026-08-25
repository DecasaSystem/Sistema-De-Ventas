<?php

namespace App\Services;

use App\Models\Usuario;
use App\Models\NominaAjuste;
use App\Models\NominaPrestamo;
use App\Models\NominaAusencia;
use App\Models\NominaBonificacion;
use App\Models\NominaPago;
use App\Models\NominaProduccion;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Cuánto se le debe a un trabajador por un ciclo, y qué ciclos están
 * esperando que alguien los cobre.
 *
 * Nada de esto se guarda: se calcula cada vez a partir del sueldo del
 * trabajador, su frecuencia de pago y las faltas y ajustes que tenga sin
 * cobrar. Solo cuando se marca "Pagado" el resultado se congela en
 * `nomina_pagos` (NominaPagoController::store).
 */
class NominaLiquidador
{
    /**
     * Cuánto se mira hacia atrás cuando alguien nunca ha cobrado. Sin este
     * piso, un trabajador diario dado de alta hace un año generaría 365
     * pagos pendientes de una.
     */
    private const DIAS_ATRAS_MAX = 90;

    /** Tope duro de ciclos por trabajador, por si el piso no alcanza. */
    private const MAX_CICLOS = 60;

    /** Tope de ventanas de bono dentro de un mismo pago (diario + mensual = 31). */
    private const MAX_VENTANAS = 40;

    /**
     * Cuánta producción se trae cargada. Más que los ciclos que se liquidan,
     * porque una ventana de bono mensual puede empezar antes que el ciclo
     * de pago que la cobra.
     */
    private const DIAS_PRODUCCION_ATRAS = 150;

    /**
     * El desglose de un ciclo para un trabajador.
     *
     * $empleado tiene que venir con `sueldo`, `ausencias` y `ajustes`
     * cargados (y esos dos filtrados a los que no se han cobrado todavía).
     *
     * Los días se cuentan desde que la persona está dada de alta y hasta
     * hoy: quien entró a mitad de quincena cobra proporcional, y un ciclo
     * en curso muestra lo que lleva devengado, no el ciclo completo.
     */
    public static function liquidar(Usuario $empleado, Carbon $inicio, Carbon $fin, Carbon $hoy): array
    {
        // Todo a la misma zona antes de restar días: mezclar medianoches de
        // husos distintos da diferencias con decimales que truncan mal.
        $hoy    = CicloNomina::fecha($hoy);
        $inicio = CicloNomina::fecha($inicio);
        $fin    = CicloNomina::fecha($fin);

        $alta  = CicloNomina::dia($empleado->created_at);
        $desde = $inicio->greaterThan($alta) ? $inicio->copy() : $alta->copy();
        $hasta = $fin->lessThan($hoy) ? $fin->copy() : $hoy->copy();

        $diasCorridos = $hasta->greaterThanOrEqualTo($desde) ? ((int) $desde->diffInDays($hasta)) + 1 : 0;
        $diasCiclo    = CicloNomina::diasPagados($empleado->periodicidad);
        $dias         = min($diasCorridos, $diasCiclo);

        $valorDia  = $empleado->valorDiaEfectivo();
        $valorHora = $empleado->valorHoraEfectivo();
        $subtotal  = round($valorDia * $dias);

        // Lo ya corrido se descuenta; lo que la persona avisó que va a
        // faltar más adelante en este mismo ciclo se muestra aparte, para
        // no bajar hoy un devengado que todavía no se perdió. Al cerrar el
        // ciclo `hasta` llega a `fin` y todo entra por el mismo lado.
        $faltas      = self::enVentana($empleado->ausencias, $inicio, $hasta);
        $programadas = $hasta->lessThan($fin)
            ? self::enVentana($empleado->ausencias, $hasta->copy()->addDay(), $fin)
            : new Collection();

        $ajustes = self::enVentana($empleado->ajustes, $inicio, $hasta);

        $descuentoFaltas = $faltas->sum(fn (NominaAusencia $a) => round((float) $a->horas * $valorHora));
        $totalAjustes    = $ajustes->sum(fn (NominaAjuste $a) => (float) $a->monto);

        // Los préstamos se descuentan solos: una cuota por pago, hasta saldar.
        // Se calculan aquí y no se guardan, para que lo que se ve antes de
        // pagar sea exactamente lo que se va a descontar.
        $prestamos = ($empleado->relationLoaded('prestamos') ? $empleado->prestamos : collect())
            ->filter(fn (NominaPrestamo $pr) => $pr->activo && ! $pr->saldado());
        $totalCuotas = $prestamos->sum(fn (NominaPrestamo $pr) => $pr->cuotaDelProximoPago());

        // Lo producido dentro del ciclo — es lo que se muestra en el detalle.
        // La bonificación puede medirse sobre otra ventana (ver abajo).
        $producciones    = self::enVentana($empleado->producciones, $inicio, $hasta);
        $produccionTotal = (float) $producciones->sum(fn (NominaProduccion $p) => (float) $p->total);

        $bono = self::evaluarBono($empleado, $inicio, $fin, $hasta);

        return [
            'periodicidad'       => $empleado->periodicidad,
            'periodicidad_label' => CicloNomina::label($empleado->periodicidad),
            'fecha_inicio'       => $inicio->toDateString(),
            'fecha_fin'          => $fin->toDateString(),
            'nombre'             => CicloNomina::nombre($empleado->periodicidad, $inicio, $fin),
            // Un ciclo cerrado es el que ya terminó: es el que se puede cobrar.
            'cerrado'            => $fin->lessThanOrEqualTo($hoy),
            'dias'               => $dias,
            'dias_ciclo'         => $diasCiclo,
            'sueldo_nombre'      => $empleado->labelEfectivo(),
            'valor_dia'          => $valorDia,
            'valor_hora'         => $valorHora,
            'horas_dia'          => $empleado->horasDiaEfectivo(),
            'subtotal'           => $subtotal,
            'descuento_faltas'   => (float) $descuentoFaltas,
            'total_ajustes'      => (float) $totalAjustes,
            'produccion_total'   => $produccionTotal,
            'bonificacion'       => $bono['monto'],
            'bono'               => $bono,
            'producciones'       => $producciones->map(fn (NominaProduccion $p) => [
                'id'             => $p->id,
                'fecha'          => $p->fecha->toDateString(),
                'concepto'       => $p->concepto,
                'valor_unitario' => (float) $p->valor_unitario,
                'cantidad'       => (float) $p->cantidad,
                'total'          => (float) $p->total,
            ])->values(),
            'total_prestamos'    => round((float) $totalCuotas, 2),
            'prestamos'          => $prestamos->map(fn (NominaPrestamo $pr) => [
                'id'          => $pr->id,
                'motivo'      => $pr->motivo,
                'monto'       => (float) $pr->monto,
                'cuotas'      => (int) $pr->cuotas,
                'valor_cuota' => (float) $pr->valor_cuota,
                'abonado'     => $pr->abonado(),
                'saldo'       => $pr->saldo(),
                'cuota_ahora' => $pr->cuotaDelProximoPago(),
            ])->values(),
            'total'              => $subtotal - (float) $descuentoFaltas + (float) $totalAjustes
                                    + $bono['monto'] - (float) $totalCuotas,
            'faltas'             => $faltas->map(fn (NominaAusencia $a) => self::faltaComoJson($a, $valorHora))->values(),
            'faltas_programadas' => $programadas->map(fn (NominaAusencia $a) => self::faltaComoJson($a, $valorHora))->values(),
            'ajustes'            => $ajustes->map(fn (NominaAjuste $a) => [
                'id'     => $a->id,
                'nombre' => $a->nombre,
                'fecha'  => $a->fecha->toDateString(),
                'monto'  => (float) $a->monto,
            ])->values(),
        ];
    }

    /**
     * La bonificación que le corresponde a este pago.
     *
     * El tope puede medirse sobre el ciclo de pago ('ciclo') o sobre una
     * ventana fija (mensual, quincenal...). Cuando no coinciden, la regla
     * es: **una ventana se cobra en el pago donde ESA ventana cierra**.
     *
     * Así un bono mensual de alguien que cobra quincenal se paga una sola
     * vez, con la quincena del 16 al 31, midiendo lo producido en todo el
     * mes; la quincena del 1 al 15 no lo paga. Y si alguien cobra mensual
     * con bono quincenal, ese único pago cierra dos ventanas y cobra las
     * dos, cada una evaluada por separado.
     *
     * La producción de la ventana se cuenta completa, esté ya enganchada a
     * un pago anterior o no: lo que se cobró antes fueron los días, no el
     * bono de esta ventana.
     */
    private static function evaluarBono(Usuario $empleado, Carbon $inicio, Carbon $fin, Carbon $hasta): array
    {
        $esquema = $empleado->bonificacion;
        if (! $esquema) {
            return NominaBonificacion::sinEsquema();
        }

        $periodo = $esquema->periodo ?? 'ciclo';
        $ventanas = $periodo === 'ciclo'
            ? [[$inicio->copy(), $fin->copy()]]
            : self::ventanasQueCierranEn($periodo, $inicio, $fin);

        $base = [
            'bonificacion_id'     => $esquema->id,
            'bonificacion_nombre' => $esquema->nombre,
            'aplica'              => true,
            'periodo'             => $periodo,
            'periodo_label'       => $esquema->labelPeriodo(),
            'cierra_aqui'         => count($ventanas) > 0,
            'tope'                => $esquema->tope_activo ? (float) $esquema->tope : null,
            'produccion_medida'   => 0.0,
            'ventanas'            => [],
        ];

        if (! $ventanas) {
            // El bono existe pero esta ventana no cierra en este pago: se
            // cobra en el que sí la cierre. No es un error, es el calendario.
            return array_merge($base, [
                'alcanzo_tope'    => false,
                'falta_para_tope' => 0.0,
                'meta'            => null,
                'monto'           => 0.0,
            ]);
        }

        $monto = 0.0;
        $detalles = [];
        $ultima = null;

        foreach ($ventanas as [$vIni, $vFin]) {
            // Hasta hoy si la ventana todavía no termina: mientras corre, lo
            // que se muestra es lo que lleva ganado, y al cerrar queda firme.
            $corte = $vFin->lessThan($hasta) ? $vFin : $hasta;
            $producido = (float) self::enVentana($empleado->producciones, $vIni, $corte)
                ->sum(fn (NominaProduccion $p) => (float) $p->total);

            $ev = $esquema->evaluar($producido);
            $monto += $ev['monto'];

            $ultima = $ev;
            $detalles[] = [
                'desde'      => $vIni->toDateString(),
                'hasta'      => $vFin->toDateString(),
                'nombre'     => CicloNomina::nombre($periodo === 'ciclo' ? $empleado->periodicidad : $periodo, $vIni, $vFin),
                'produccion' => $producido,
                'cerrada'    => $vFin->lessThanOrEqualTo($hasta),
                'meta'       => $ev['meta'],
                'monto'      => $ev['monto'],
            ];
        }

        return array_merge($base, [
            'produccion_medida' => (float) array_sum(array_column($detalles, 'produccion')),
            'alcanzo_tope'      => $ultima['alcanzo_tope'],
            'falta_para_tope'   => $ultima['falta_para_tope'],
            'meta'              => $ultima['meta'],
            'monto'             => $monto,
            'ventanas'          => $detalles,
        ]);
    }

    /**
     * Las ventanas de esa frecuencia que terminan dentro del ciclo de pago.
     * Son las que este pago tiene que cobrar — ninguna otra las va a
     * reclamar, y por eso el bono no se paga dos veces.
     *
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    private static function ventanasQueCierranEn(string $periodo, Carbon $inicio, Carbon $fin): array
    {
        [$vIni, $vFin] = CicloNomina::rango($periodo, $inicio);

        // Si la ventana del arranque cerró antes del ciclo, ya la cobró un
        // pago anterior: se avanza hasta la primera que caiga adentro.
        $vueltas = 0;
        while ($vFin->lessThan($inicio) && $vueltas++ < self::MAX_VENTANAS) {
            [$vIni, $vFin] = CicloNomina::siguiente($periodo, $vIni);
        }

        $ventanas = [];
        $vueltas = 0;
        while ($vFin->lessThanOrEqualTo($fin) && $vueltas++ < self::MAX_VENTANAS) {
            $ventanas[] = [$vIni->copy(), $vFin->copy()];
            [$vIni, $vFin] = CicloNomina::siguiente($periodo, $vIni);
        }

        return $ventanas;
    }

    /** El ciclo en curso de un trabajador — lo que lleva devengado hoy. */
    public static function cicloActual(Usuario $empleado, Carbon $hoy): array
    {
        [$inicio, $fin] = CicloNomina::rango($empleado->periodicidad, $hoy);

        return self::liquidar($empleado, $inicio, $fin, $hoy);
    }

    /**
     * Todo lo que está esperando que alguien lo cobre: para cada trabajador
     * activo, los ciclos ya cerrados que todavía no tienen pago.
     *
     * El ciclo en curso no entra — aparece cuando termina (la quincena el
     * día 15, la semana el domingo, el diario el mismo día).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function pendientes(Carbon $hoy): array
    {
        $hoy = CicloNomina::fecha($hoy);

        $empleados = self::empleadosLiquidables();
        if ($empleados->isEmpty()) {
            return [];
        }

        $pisoGlobal = $hoy->copy()->subDays(self::DIAS_ATRAS_MAX);

        // Hasta dónde se le pagó a cada uno y qué ciclos ya tienen pago, en
        // dos consultas y no dos por trabajador.
        $ultimoFin = NominaPago::whereIn('usuario_id', $empleados->pluck('id'))
            ->selectRaw('usuario_id, MAX(fecha_fin) as ultimo_fin')
            ->groupBy('usuario_id')
            ->pluck('ultimo_fin', 'usuario_id');

        $yaPagados = NominaPago::whereIn('usuario_id', $empleados->pluck('id'))
            ->where('fecha_fin', '>=', $pisoGlobal->toDateString())
            ->get(['usuario_id', 'fecha_inicio'])
            ->map(fn (NominaPago $p) => $p->usuario_id . '|' . $p->fecha_inicio->toDateString())
            ->flip();

        $pendientes = [];

        foreach ($empleados as $empleado) {
            $ultimo = $ultimoFin[$empleado->id] ?? null;

            // Desde el día siguiente al último pago; si nunca cobró, desde
            // que está dado de alta (sin irse más atrás que el piso).
            if ($ultimo) {
                $piso = CicloNomina::fecha($ultimo)->addDay();
            } else {
                $alta = CicloNomina::dia($empleado->created_at);
                $piso = $alta->greaterThan($pisoGlobal) ? $alta : $pisoGlobal->copy();
            }

            if ($piso->greaterThan($hoy)) {
                continue;
            }

            [$inicio, $fin] = CicloNomina::rango($empleado->periodicidad, $piso);

            for ($vuelta = 0; $vuelta < self::MAX_CICLOS && $fin->lessThanOrEqualTo($hoy); $vuelta++) {
                if (! $yaPagados->has($empleado->id . '|' . $inicio->toDateString())) {
                    $liquidacion = self::liquidar($empleado, $inicio, $fin, $hoy);

                    if ($liquidacion['dias'] > 0) {
                        $pendientes[] = [
                            'usuario_id'      => $empleado->id,
                            'empleado_nombre' => $empleado->nombre,
                            // El cargo ya no es texto suelto: es el rol del
                            // trabajador, que se mantiene desde Trabajadores.
                            'empleado_cargo'  => $empleado->rolAsignado?->nombre,
                            'empleado_cedula' => $empleado->cedula,
                        ] + $liquidacion;
                    }
                }

                [$inicio, $fin] = CicloNomina::siguiente($empleado->periodicidad, $inicio);
            }
        }

        return $pendientes;
    }

    /**
     * Los activos que se pueden liquidar. Quien no tiene sueldo asignado
     * queda fuera a propósito: liquidarlo daría $0 y ese cero se vería
     * igual que un pago legítimo (la pantalla avisa aparte cuántos son).
     */
    public static function empleadosLiquidables()
    {
        return Usuario::with(self::relaciones())
            ->where('activo', true)
            ->whereNotNull('nomina_sueldo_id')
            ->orderBy('nombre')
            ->get();
    }

    /**
     * Lo que `liquidar()` necesita tener cargado. Las tres listas se
     * filtran a lo que no se ha cobrado todavía: es lo único que puede
     * entrar en un ciclo pendiente, y así no crecen sin techo con los años.
     *
     * Método y no constante porque `with()` solo entiende Closures como
     * filtro — un callable en formato array lo lee como más nombres de
     * relación e intenta cargar una que no existe.
     */
    public static function relaciones(): array
    {
        $sinCobrar = fn ($q) => $q->whereNull('nomina_pago_id')->orderBy('fecha');
        // Los préstamos vivos van completos con sus cuotas: el saldo se saca
        // de ahí, así que filtrarlos por "sin cobrar" daría un saldo inflado.
        $prestamosVivos = fn ($q) => $q->where('activo', true)->with('cuotasPagadas');

        // La producción NO se filtra por "sin cobrar", a diferencia de las
        // otras dos: un bono mensual que se paga con la segunda quincena
        // tiene que poder sumar la producción de la primera, que ya quedó
        // enganchada a ese pago. Se acota por fecha para que no crezca sola.
        $piso = CicloNomina::hoy()->subDays(self::DIAS_PRODUCCION_ATRAS)->toDateString();
        $recientes = fn ($q) => $q->where('fecha', '>=', $piso)->orderBy('fecha');

        return [
            'sueldo',
            'rolAsignado:id,nombre',
            'bonificacion.metas',
            'ausencias'    => $sinCobrar,
            'ajustes'      => $sinCobrar,
            'prestamos'    => $prestamosVivos,
            'producciones' => $recientes,
        ];
    }

    /** Las filas de una colección ya cargada que caen dentro de un rango. */
    private static function enVentana($items, Carbon $desde, Carbon $hasta)
    {
        return $items->filter(function ($i) use ($desde, $hasta) {
            $f = CicloNomina::fecha($i->fecha);

            return $f->greaterThanOrEqualTo($desde) && $f->lessThanOrEqualTo($hasta);
        })->values();
    }

    private static function faltaComoJson(NominaAusencia $a, float $valorHora): array
    {
        return [
            'id'            => $a->id,
            'fecha'         => $a->fecha->toDateString(),
            'horas'         => (float) $a->horas,
            'motivo'        => $a->motivo,
            'registrada_en' => $a->created_at->toIso8601String(),
            'monto'         => round((float) $a->horas * $valorHora),
        ];
    }
}
