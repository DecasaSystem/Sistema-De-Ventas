<?php

namespace App\Services\Finanzas;

use App\Services\CicloNomina;
use App\Services\NominaLiquidador;
use Carbon\Carbon;

/**
 * Lo que hay que pagar en los próximos días, con fecha: la nómina de cada
 * frecuencia el día que cierra su ciclo, las comisiones el 20 (o al cierre
 * del trimestre), los gastos de las plantillas en su día y el IVA del
 * periodo. Lo atrasado sale primero. Cada renglón dice si el monto es exacto
 * o estimado.
 */
class CalendarioPagos
{
    public static function proximos(int $dias = 45): array
    {
        $hoy = Periodo::hoy();
        $hasta = $hoy->copy()->addDays($dias);
        $items = [];

        // Nómina atrasada: ciclos cerrados sin pagar.
        $atrasada = collect(NominaLiquidador::pendientes(CicloNomina::hoy()));
        if ($atrasada->isNotEmpty()) {
            $items[] = [
                'fecha' => $atrasada->min('fecha_fin'), 'tipo' => 'nomina', 'vencido' => true, 'estimado' => false,
                'titulo' => 'Nómina por pagar',
                'detalle' => $atrasada->count() . ' pago(s) de ciclos ya cerrados',
                'monto' => round($atrasada->sum('total')),
            ];
        }

        // Nómina que viene: un renglón por fecha y frecuencia.
        $futura = collect(FuenteNomina::proyeccion($hasta)['pagos'])
            ->filter(fn ($p) => $p['fecha_pago'] >= $hoy->toDateString() && $p['fecha_pago'] <= $hasta->toDateString())
            ->groupBy(fn ($p) => $p['fecha_pago'] . '|' . $p['periodicidad']);
        foreach ($futura as $grupo) {
            $p = $grupo->first();
            $items[] = [
                'fecha' => $p['fecha_pago'], 'tipo' => 'nomina', 'vencido' => false, 'estimado' => true,
                'titulo' => 'Nómina ' . mb_strtolower(CicloNomina::label($p['periodicidad'])),
                'detalle' => $grupo->count() . ' persona(s) · + ' . self::pesos($grupo->sum('aportes')) . ' de aportes (planilla)',
                'monto' => round($grupo->sum('neto')),
            ];
        }

        // Comisiones: las listas atrasadas y las que quedan disponibles.
        foreach (FuenteComisiones::porPagar() as $c) {
            if ($c['fecha'] > $hasta->toDateString()) continue;
            $vencido = $c['fecha'] < $hoy->toDateString();
            if ($vencido && $c['listas'] <= 0) continue;
            $items[] = [
                'fecha' => $c['fecha'], 'tipo' => 'comisiones', 'vencido' => $vencido, 'estimado' => true,
                'titulo' => $vencido ? 'Comisiones listas sin pagar' : 'Comisiones',
                'detalle' => $vencido ? 'Ya se pueden pagar' : 'Al día de hoy; se mueven hasta que se paguen',
                'monto' => round($vencido ? $c['listas'] : $c['monto']),
            ];
        }

        // Gastos de las plantillas.
        foreach (ObligacionesRecurrentes::pendientes($dias, $hoy) as $o) {
            $items[] = [
                'fecha' => $o['vence'], 'tipo' => 'gasto', 'vencido' => $o['estado'] === 'vencida',
                'estimado' => $o['monto_estimado'],
                'titulo' => $o['nombre'],
                'detalle' => trim(($o['categoria'] ?? '') . ($o['tienda'] ? ' · ' . $o['tienda'] : '')),
                'monto' => round($o['monto']),
                'gasto_recurrente_id' => $o['gasto_recurrente_id'],
                'periodo' => $o['periodo'],
            ];
        }

        // Facturas de proveedores a crédito: las vencidas y las que vencen en la ventana.
        foreach (FuenteGastos::facturasPorPagar() as $f) {
            if ($f['vence'] > $hasta->toDateString()) continue;
            $items[] = [
                'fecha' => $f['vence'], 'tipo' => 'proveedor', 'vencido' => $f['vence'] < $hoy->toDateString(), 'estimado' => false,
                'titulo' => trim(($f['proveedor'] ?? 'Proveedor') . ' · ' . $f['concepto']),
                'detalle' => $f['numero'] ? 'Factura ' . $f['numero'] : 'Factura a crédito',
                'monto' => round($f['saldo']),
                'cuenta_por_pagar_id' => $f['id'],
            ];
        }

        // Prestaciones: la prima de junio y diciembre, los intereses de enero y
        // las cesantías de febrero, con lo que se va provisionando.
        foreach (\App\Services\Prestaciones::vencimientos($hoy->copy()->subDays(30), $hasta) as $v) {
            $vencido = $v['fecha'] < $hoy->toDateString();
            $items[] = [
                'fecha' => $v['fecha'], 'tipo' => 'prestaciones', 'vencido' => $vencido, 'estimado' => $v['estimado'],
                'titulo' => $v['nombre'] . ($v['tipo'] === 'cesantias' ? ' (al fondo)' : ''),
                'detalle' => $v['estimado'] ? 'Proyectado al cierre del periodo; se paga en Nómina → Prestaciones' : 'Se paga en Nómina → Prestaciones',
                'monto' => $v['monto'],
            ];
        }

        // IVA del periodo que se declara en la ventana (aprox. día 15 del mes siguiente).
        foreach (self::periodosIva($hoy, $hasta) as [$desde, $hastaMes, $declara]) {
            $iva = array_sum(array_column(FuenteVentas::vendidoPorMes($desde, $hastaMes), 'iva'));
            if ($iva <= 0) continue;
            $items[] = [
                'fecha' => $declara, 'tipo' => 'impuesto', 'vencido' => false, 'estimado' => true,
                'titulo' => 'IVA de ' . Periodo::nombre($desde) . ' a ' . Periodo::nombre($hastaMes),
                'detalle' => 'Generado por las ventas, sin descontar el de las compras. Fecha aproximada: confírmala con el contador',
                'monto' => round($iva),
            ];
        }

        usort($items, fn ($a, $b) => [$b['vencido'], $a['fecha']] <=> [$a['vencido'], $b['fecha']]);

        return $items;
    }

    /** Los periodos de IVA cuya declaración (día 15 del mes siguiente) cae en la ventana. */
    private static function periodosIva(Carbon $hoy, Carbon $hasta): array
    {
        $largo = ConfigFinanzas::get('periodo_iva') === 'cuatrimestral' ? 4 : 2;
        $out = [];
        $anio = (int) $hoy->format('Y');
        foreach ([$anio - 1, $anio, $anio + 1] as $y) {
            for ($m = 1; $m <= 12; $m += $largo) {
                $desde = sprintf('%04d-%02d', $y, $m);
                $fin = Periodo::sumarMeses($desde, $largo - 1);
                $declara = Periodo::sumarMeses($fin, 1) . '-15';
                if ($declara >= $hoy->toDateString() && $declara <= $hasta->toDateString()) {
                    $out[] = [$desde, $fin, $declara];
                }
            }
        }

        return $out;
    }

    private static function pesos(float $n): string
    {
        return '$' . number_format($n, 0, ',', '.');
    }
}
