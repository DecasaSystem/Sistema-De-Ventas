<?php

namespace App\Services;

use App\Models\Empleado;
use App\Models\NominaAjuste;
use App\Models\NominaAusencia;
use App\Models\NominaPago;
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
    public static function liquidar(Empleado $empleado, Carbon $inicio, Carbon $fin, Carbon $hoy): array
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
            'total'              => $subtotal - (float) $descuentoFaltas + (float) $totalAjustes,
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

    /** El ciclo en curso de un trabajador — lo que lleva devengado hoy. */
    public static function cicloActual(Empleado $empleado, Carbon $hoy): array
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
        $ultimoFin = NominaPago::whereIn('empleado_id', $empleados->pluck('id'))
            ->selectRaw('empleado_id, MAX(fecha_fin) as ultimo_fin')
            ->groupBy('empleado_id')
            ->pluck('ultimo_fin', 'empleado_id');

        $yaPagados = NominaPago::whereIn('empleado_id', $empleados->pluck('id'))
            ->where('fecha_fin', '>=', $pisoGlobal->toDateString())
            ->get(['empleado_id', 'fecha_inicio'])
            ->map(fn (NominaPago $p) => $p->empleado_id . '|' . $p->fecha_inicio->toDateString())
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
                            'empleado_id'     => $empleado->id,
                            'empleado_nombre' => $empleado->nombre,
                            'empleado_cargo'  => $empleado->cargo,
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
        return Empleado::with([
                'sueldo',
                'ausencias' => fn ($q) => $q->whereNull('nomina_pago_id')->orderBy('fecha'),
                'ajustes'   => fn ($q) => $q->whereNull('nomina_pago_id')->orderBy('fecha'),
            ])
            ->where('activo', true)
            ->whereNotNull('nomina_sueldo_id')
            ->orderBy('nombre')
            ->get();
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
