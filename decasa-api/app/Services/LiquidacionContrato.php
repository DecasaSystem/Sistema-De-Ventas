<?php

namespace App\Services;

use App\Http\Controllers\NominaPagoController;
use App\Models\NominaLiquidacion;
use App\Models\NominaPago;
use App\Models\NominaPrestacionPago;
use App\Models\NominaPrestamo;
use App\Models\NominaPrestamoCuota;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * La liquidación de quien se va:
 *
 *   + salario de los ciclos sin pagar, hasta el día del retiro (neto, como en
 *     cualquier pago: ya descuenta su seguridad social y la cuota del préstamo)
 *   + prima del semestre (y la del anterior si no se pagó)
 *   + cesantías e intereses del año (y del anterior si no se consignaron):
 *     al retiro se le pagan a la persona, no al fondo
 *   + vacaciones pendientes (días × sueldo del día)
 *   + indemnización, solo en despido sin justa causa (art. 64 CST, contrato
 *     indefinido: 30 días el primer año + 20 por cada año más, proporcional;
 *     20 y 15 si gana 10 mínimos o más; en contrato fijo u obra, el monto lo
 *     pone la persona: son los salarios que faltaban)
 *   − lo que le queda de préstamos
 *   ± otros (lo que se acuerde)
 *
 * Las prestaciones salen de lo provisionado en cada pago (Prestaciones), así
 * que cuadran con lo que Finanzas ya contó como costo. Los días y montos de
 * ley (vacaciones al año, indemnización, mínimo) se editan en Nómina.
 *
 * Al registrarla: se pagan los ciclos pendientes (quedan como pagos normales
 * de nómina), se guardan las prestaciones pagadas, el préstamo queda saldado
 * y la persona sale de nómina (se le quita el sueldo y se marca el retiro).
 * No se desactiva su usuario: eso se hace en Trabajadores.
 */
class LiquidacionContrato
{
    public static function calcular(Usuario $u, Carbon $retiro, string $motivo, ?string $tipoContrato, array $op = []): array
    {
        $u->loadMissing(NominaLiquidador::relaciones());
        $retiro = CicloNomina::fecha($retiro);
        $ingreso = CicloNomina::fecha($u->nomina_desde ?? $u->created_at);

        if (! $u->nomina_sueldo_id) {
            throw ValidationException::withMessages(['usuario_id' => ["{$u->nombre} no tiene sueldo asignado en nómina."]]);
        }
        if ($retiro->lessThan($ingreso)) {
            throw ValidationException::withMessages(['fecha_retiro' => ['El retiro no puede ser antes de que entrara a nómina.']]);
        }
        $ultimoFin = NominaPago::where('usuario_id', $u->id)->max('fecha_fin');
        if ($ultimoFin && CicloNomina::fecha($ultimoFin)->greaterThan($retiro)) {
            throw ValidationException::withMessages(['fecha_retiro' => ["Ya tiene pagos de nómina hasta el {$ultimoFin}: el retiro tiene que ser después."]]);
        }

        // 1. Ciclos sin pagar hasta el retiro.
        $ciclos = [];
        $piso = $ultimoFin ? CicloNomina::fecha($ultimoFin)->addDay() : $ingreso->copy();
        [$ini, $fin] = CicloNomina::rango($u->periodicidad, $piso);
        $pagados = NominaPago::where('usuario_id', $u->id)->pluck('fecha_inicio')->map(fn ($f) => CicloNomina::fecha($f)->toDateString())->flip();
        for ($i = 0; $i < 400 && $ini->lessThanOrEqualTo($retiro); $i++) {
            if (! $pagados->has($ini->toDateString())) {
                $l = NominaLiquidador::liquidar($u, $ini, $fin, $retiro);
                if ($l['dias'] > 0) {
                    $corte = $fin->lessThan($retiro) ? $fin : $retiro;
                    $ciclos[] = $l + ['usuario_id' => $u->id, 'fecha_corte' => $corte->toDateString()];
                }
            }
            [$ini, $fin] = CicloNomina::siguiente($u->periodicidad, $ini);
        }

        $conceptos = [];
        $salario = array_sum(array_column($ciclos, 'total'));
        if ($salario != 0) {
            $conceptos[] = [
                'clave' => 'salario', 'nombre' => 'Salario pendiente',
                'detalle' => count($ciclos) . ' ciclo(s) sin pagar, hasta el ' . $retiro->toDateString() . ' (ya descuenta seguridad social y cuotas)',
                'monto' => round($salario),
            ];
        }

        // 2. Prestaciones: el periodo del retiro y el anterior, si quedó debiendo.
        $periodos = [];
        foreach (['prima', 'cesantias', 'intereses_cesantias'] as $tipo) {
            [$pIni, $pFin] = Prestaciones::periodo($tipo, $retiro);
            [$aIni, $aFin] = Prestaciones::periodo($tipo, $pIni->copy()->subDay());
            foreach ([[$aIni, $aFin], [$pIni, $retiro]] as [$d, $h]) {
                if ($h->lessThan($ingreso)) continue;
                $causado = Prestaciones::causado($tipo, $d, $h, $u->id, $ciclos)[$u->id]['monto'] ?? 0;
                // Lo pagado se busca en el periodo entero (la prima de junio, la
                // consignación del año): ahí cae también un pago parcial.
                [$entD, $entH] = Prestaciones::periodo($tipo, $d);
                $pagado = Prestaciones::pagado($tipo, $entD, $entH, $u->id)[$u->id]['monto'] ?? 0;
                $saldo = round($causado - $pagado);
                if ($saldo <= 0) continue;
                $periodos[] = ['tipo' => $tipo, 'desde' => $d->toDateString(), 'hasta' => $h->toDateString(), 'monto' => $saldo];
                $conceptos[] = [
                    'clave' => $tipo, 'nombre' => Prestaciones::NOMBRES[$tipo],
                    'detalle' => 'Del ' . $d->toDateString() . ' al ' . $h->toDateString(),
                    'monto' => $saldo, 'desde' => $d->toDateString(), 'hasta' => $h->toDateString(),
                ];
            }
        }

        // 3. Vacaciones pendientes, en días.
        $vac = collect(Prestaciones::vacaciones($u->id))->firstWhere('usuario_id', $u->id);
        $diasCiclos = array_sum(array_column($ciclos, 'dias'));
        // vacaciones() ya cuenta lo sin pagar a hoy; con corte al retiro se recalcula con los ciclos de aquí.
        $diasTrab = (float) NominaPago::where('usuario_id', $u->id)->sum('dias') + $diasCiclos;
        $diasVac = 0.0;
        if (($vac['aplica'] ?? true)) {
            $diasVac = max(0, round($diasTrab * Prestaciones::config()['dias_vacaciones_anio'] / 360 - ($vac['dias_tomados'] ?? 0), 2));
        }
        if ($diasVac > 0) {
            $conceptos[] = [
                'clave' => 'vacaciones', 'nombre' => 'Vacaciones pendientes',
                'detalle' => "{$diasVac} días × " . number_format($u->valorDiaEfectivo(), 0, ',', '.'),
                'monto' => round($diasVac * $u->valorDiaEfectivo()), 'dias' => $diasVac,
            ];
        }

        // 4. Indemnización.
        $indem = self::indemnizacion($u, $ingreso, $retiro, $motivo, $tipoContrato, $op['indemnizacion_manual'] ?? null);
        if ($indem['monto'] > 0) {
            $conceptos[] = ['clave' => 'indemnizacion', 'nombre' => 'Indemnización', 'detalle' => $indem['detalle'], 'monto' => $indem['monto']];
        }

        // 5. Otros (lo que se acuerde, + o −).
        foreach ($op['otros'] ?? [] as $o) {
            if (! (float) ($o['monto'] ?? 0)) continue;
            $conceptos[] = ['clave' => 'otro', 'nombre' => mb_substr($o['nombre'] ?? 'Otro', 0, 80), 'detalle' => 'Acordado', 'monto' => round((float) $o['monto'])];
        }

        // 6. Préstamos: lo que queda después de las cuotas de los ciclos de arriba.
        $deducciones = [];
        if ($op['descontar_prestamos'] ?? true) {
            $cuotasCiclos = array_sum(array_column($ciclos, 'total_prestamos'));
            $saldoPrestamos = NominaPrestamo::with('cuotasPagadas')->where('usuario_id', $u->id)->where('activo', true)->get()
                ->sum(fn ($p) => $p->saldo());
            $resto = max(0, round($saldoPrestamos - $cuotasCiclos));
            if ($resto > 0) {
                $deducciones[] = ['clave' => 'prestamos', 'nombre' => 'Saldo de préstamos', 'monto' => $resto];
            }
        }

        $total = array_sum(array_column($conceptos, 'monto')) - array_sum(array_column($deducciones, 'monto'));

        return [
            'usuario_id'     => $u->id,
            'nombre'         => $u->nombre,
            'cedula'         => $u->cedula,
            'cargo'          => $u->rolAsignado?->nombre,
            'fecha_ingreso'  => $ingreso->toDateString(),
            'fecha_retiro'   => $retiro->toDateString(),
            'dias_servicio'  => $ingreso->diffInDays($retiro) + 1,
            'motivo'         => $motivo,
            'motivo_nombre'  => NominaLiquidacion::MOTIVOS[$motivo] ?? $motivo,
            'tipo_contrato'  => $tipoContrato,
            'sueldo'         => ['nombre' => $u->labelEfectivo(), 'valor_dia' => $u->valorDiaEfectivo(), 'mensual' => round($u->valorDiaEfectivo() * 30)],
            'ciclos'         => array_map(fn ($c) => [
                'fecha_inicio' => $c['fecha_inicio'], 'fecha_corte' => $c['fecha_corte'], 'nombre' => $c['nombre'],
                'dias' => $c['dias'], 'total' => round($c['total']),
            ], $ciclos),
            'conceptos'      => $conceptos,
            'deducciones'    => $deducciones,
            'periodos'       => $periodos,
            'indemnizacion'  => $indem,
            'total'          => round($total),
        ];
    }

    /** Indemnización por despido sin justa causa (art. 64 CST). */
    public static function indemnizacion(Usuario $u, Carbon $ingreso, Carbon $retiro, string $motivo, ?string $tipo, $manual = null): array
    {
        if ($manual !== null && $manual !== '') {
            return ['monto' => round((float) $manual), 'dias' => null, 'detalle' => 'Monto puesto a mano'];
        }
        if ($motivo !== 'despido_sin_justa_causa') {
            return ['monto' => 0, 'dias' => 0, 'detalle' => 'No aplica: ' . (NominaLiquidacion::MOTIVOS[$motivo] ?? $motivo)];
        }
        if ($tipo && $tipo !== 'indefinido') {
            return ['monto' => 0, 'dias' => null, 'detalle' => 'En contrato ' . (NominaLiquidacion::CONTRATOS[$tipo] ?? $tipo) . ' son los salarios del tiempo que faltaba: ponlo a mano'];
        }

        $c = Prestaciones::config();
        $mensual = $u->valorDiaEfectivo() * 30;
        $alto = $mensual >= $c['smmlv'] * $c['indemnizacion_tope_smmlv'];
        $primer = (float) ($alto ? $c['indemnizacion_primer_anio_alto'] : $c['indemnizacion_primer_anio']);
        $adicional = (float) ($alto ? $c['indemnizacion_anio_adicional_alto'] : $c['indemnizacion_anio_adicional']);
        $anios = ($ingreso->diffInDays($retiro) + 1) / 360;
        $dias = $anios <= 1 ? $primer : $primer + $adicional * ($anios - 1);

        return [
            'monto'   => round($dias * $u->valorDiaEfectivo()),
            'dias'    => round($dias, 2),
            'detalle' => round($dias, 1) . ' días de sueldo (' . round($anios, 2) . ' años: ' . $primer . ' el primero + ' . $adicional . ' por cada año más)',
        ];
    }

    /** Registra la liquidación: paga, guarda y saca a la persona de nómina. */
    public static function registrar(Usuario $u, array $calc, array $datos, int $registradoPor): NominaLiquidacion
    {
        return DB::transaction(function () use ($u, $calc, $datos, $registradoPor) {
            $retiro = CicloNomina::fecha($calc['fecha_retiro']);
            $fechaPago = $datos['fecha_pago'] ?? CicloNomina::hoy()->toDateString();

            // 1. Los ciclos pendientes, como pagos normales de nómina (hasta el retiro).
            $pagosIds = [];
            $u->load(NominaLiquidador::relaciones());
            foreach ($calc['ciclos'] as $c) {
                $pago = app(NominaPagoController::class)->registrar(
                    $u->fresh(NominaLiquidador::relaciones()), CicloNomina::fecha($c['fecha_inicio']), 'Liquidación al retiro', $retiro);
                $pagosIds[] = $pago->id;
            }

            $liq = NominaLiquidacion::create([
                'usuario_id'     => $u->id,
                'fecha_ingreso'  => $calc['fecha_ingreso'],
                'fecha_retiro'   => $calc['fecha_retiro'],
                'motivo'         => $calc['motivo'],
                'tipo_contrato'  => $calc['tipo_contrato'],
                'total'          => $calc['total'],
                'detalle'        => $calc + [
                    'nomina_pagos_ids'        => $pagosIds,
                    'sueldo_id_anterior'      => $u->nomina_sueldo_id,
                    'bonificacion_id_anterior' => $u->nomina_bonificacion_id,
                ],
                'estado'         => 'pagada',
                'fecha_pago'     => $fechaPago,
                'notas'          => $datos['notas'] ?? null,
                'registrado_por' => $registradoPor,
            ]);

            // 2. Las prestaciones que se pagan.
            foreach ($calc['conceptos'] as $c) {
                if (! in_array($c['clave'], ['prima', 'cesantias', 'intereses_cesantias', 'vacaciones', 'indemnizacion'], true)) continue;
                NominaPrestacionPago::create([
                    'usuario_id'     => $u->id,
                    'tipo'           => $c['clave'],
                    'periodo_desde'  => $c['desde'] ?? $calc['fecha_ingreso'],
                    'periodo_hasta'  => $c['hasta'] ?? $calc['fecha_retiro'],
                    'dias'           => $c['dias'] ?? null,
                    'monto'          => $c['monto'],
                    'forma'          => 'pago',
                    'fecha_pago'     => $fechaPago,
                    'liquidacion_id' => $liq->id,
                    'notas'          => 'Liquidación al retiro',
                    'registrado_por' => $registradoPor,
                ]);
            }

            // 3. El préstamo queda saldado con lo que se descontó.
            $cuotas = [];
            if (collect($calc['deducciones'])->firstWhere('clave', 'prestamos')) {
                foreach (NominaPrestamo::with('cuotasPagadas')->where('usuario_id', $u->id)->where('activo', true)->get() as $p) {
                    $saldo = $p->saldo();
                    if ($saldo <= 0) continue;
                    $cuotas[] = NominaPrestamoCuota::create([
                        'prestamo_id' => $p->id, 'nomina_pago_id' => end($pagosIds) ?: null,
                        'monto' => $saldo, 'fecha' => $fechaPago,
                    ])->id;
                }
            }

            // 4. Sale de nómina (el usuario no se desactiva: eso es en Trabajadores).
            $u->update([
                'nomina_retiro'          => $calc['fecha_retiro'],
                'nomina_sueldo_id'       => null,
                'nomina_bonificacion_id' => null,
                'nomina_tipo_contrato'   => $calc['tipo_contrato'] ?? $u->nomina_tipo_contrato,
            ]);

            $detalle = $liq->detalle;
            $detalle['cuotas_ids'] = $cuotas;
            $liq->update(['detalle' => $detalle]);

            return $liq;
        });
    }

    /**
     * Deshace una liquidación registrada por error: vuelve a poner a la
     * persona en nómina con su sueldo, borra los pagos de ciclos que creó (la
     * nómina los vuelve a mostrar pendientes), anula las prestaciones y
     * devuelve la deuda del préstamo. La liquidación queda anulada, no se borra.
     */
    public static function anular(NominaLiquidacion $liq, string $motivo): void
    {
        if ($liq->estado === 'anulada') {
            throw ValidationException::withMessages(['estado' => ['Ya estaba anulada.']]);
        }

        DB::transaction(function () use ($liq, $motivo) {
            $d = $liq->detalle;
            NominaPrestamoCuota::whereIn('id', $d['cuotas_ids'] ?? [])->delete();
            foreach ($d['nomina_pagos_ids'] ?? [] as $pagoId) {
                \App\Models\NominaAusencia::where('nomina_pago_id', $pagoId)->update(['nomina_pago_id' => null]);
                \App\Models\NominaAjuste::where('nomina_pago_id', $pagoId)->update(['nomina_pago_id' => null]);
                \App\Models\NominaProduccion::where('nomina_pago_id', $pagoId)->update(['nomina_pago_id' => null]);
                NominaPrestamoCuota::where('nomina_pago_id', $pagoId)->delete();
                NominaPago::where('id', $pagoId)->delete();
            }
            NominaPrestacionPago::where('liquidacion_id', $liq->id)
                ->update(['estado' => 'anulado', 'motivo_anulacion' => 'Liquidación anulada: ' . $motivo]);
            $liq->trabajador?->update([
                'nomina_retiro'          => null,
                'nomina_sueldo_id'       => $d['sueldo_id_anterior'] ?? null,
                'nomina_bonificacion_id' => $d['bonificacion_id_anterior'] ?? null,
            ]);
            $liq->update(['estado' => 'anulada', 'motivo_anulacion' => mb_substr($motivo, 0, 200)]);
        });
    }
}
