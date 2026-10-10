<?php

namespace App\Services\Finanzas;

/**
 * El flujo de caja de cada mes: la plata que de verdad entró y salió, el mes
 * en que entró o salió. Es la pregunta "¿me alcanza para la quincena?", que
 * el estado de resultados no contesta (se puede ganar plata y quedarse sin
 * caja si los clientes deben mucho).
 *
 *   + cobros a clientes (todos los medios; ya resta reembolsos)
 *   − nómina pagada (neto + seguridad social del trabajador + aportes del empleador)
 *   − comisiones pagadas (neto de anticipos) − anticipos de comisión
 *   − gastos pagados y compras del taller
 *   = flujo neto
 *
 * Con un saldo inicial (Finanzas → Ajustes: "en caja y bancos al cierre de
 * tal mes") también da el saldo al cierre de cada mes. Sin él, solo el flujo.
 * Las prestaciones no salen el mes en que se causan: se muestran como
 * provisión acumulada para que la prima de junio y diciembre no sorprenda.
 */
class FlujoDeCaja
{
    public static function meses(string $desde, string $hasta): array
    {
        $cobros   = FuenteVentas::cobradoPorMes($desde, $hasta);
        $nomina   = FuenteNomina::porMes($desde, $hasta, false);
        $comision = FuenteComisiones::cajaPorMes($desde, $hasta);
        $gastos   = FuenteGastos::porMes($desde, $hasta);

        $filas = [];
        foreach (Periodo::meses($desde, $hasta) as $mes) {
            $entradas = (float) ($cobros[$mes]['cobrado'] ?? 0);
            $salNom   = (float) ($nomina[$mes]['caja'] ?? 0);
            $salCom   = (float) ($comision[$mes]['caja'] ?? 0);
            $salGas   = (float) ($gastos[$mes]['caja'] ?? 0);
            $salidas  = $salNom + $salCom + $salGas;

            $filas[] = [
                'mes'        => $mes,
                'nombre'     => Periodo::nombre($mes),
                'entradas'   => round($entradas),
                'por_metodo' => array_map('round', $cobros[$mes]['por_metodo'] ?? []),
                'salidas'    => [
                    'total'      => round($salidas),
                    'nomina'     => round($salNom),
                    'comisiones' => round($salCom),
                    'gastos'     => round($salGas),
                ],
                'neto'       => round($entradas - $salidas),
            ];
        }

        // El saldo, si se conoce uno de partida.
        $saldoMes = ConfigFinanzas::get('saldo_fecha');
        $saldo    = ConfigFinanzas::get('saldo_inicial');
        if ($saldo !== null && Periodo::valido($saldoMes)) {
            $acumulado = (float) $saldo;
            // Lo que pasó entre el mes del saldo y el primero que se muestra.
            if ($saldoMes < $desde) {
                $previo = Periodo::sumarMeses($saldoMes, 1);
                $antes  = Periodo::sumarMeses($desde, -1);
                if ($previo <= $antes) {
                    foreach (self::meses($previo, $antes)['meses'] as $f) $acumulado += $f['neto'];
                }
            }
            foreach ($filas as &$f) {
                if ($f['mes'] > $saldoMes) {
                    $acumulado += $f['neto'];
                    $f['saldo'] = round($acumulado);
                } elseif ($f['mes'] === $saldoMes) {
                    $f['saldo'] = round((float) $saldo);
                }
            }
            unset($f);
        }

        return [
            'meses'       => $filas,
            'con_saldo'   => $saldo !== null,
            'saldo_desde' => $saldoMes,
        ];
    }

    /** El saldo a hoy (fin del mes en curso, estimado), o null si no hay saldo de partida. */
    public static function saldoActual(): ?float
    {
        $saldoMes = ConfigFinanzas::get('saldo_fecha');
        $saldo = ConfigFinanzas::get('saldo_inicial');
        if ($saldo === null || ! Periodo::valido($saldoMes)) return null;

        $actual = Periodo::mesActual();
        if ($saldoMes >= $actual) return (float) $saldo;

        $acumulado = (float) $saldo;
        foreach (self::meses(Periodo::sumarMeses($saldoMes, 1), $actual)['meses'] as $f) {
            $acumulado += $f['neto'];
        }

        return round($acumulado);
    }
}
