<?php

namespace App\Services\Finanzas;

/**
 * Los indicadores del mes y el semáforo de salud, siempre con palabras: un
 * color solo no le dice nada a quien no lee finanzas.
 *
 * - Punto de equilibrio: cuánto hay que vender (con IVA) para no perder.
 *     costos fijos (promedio de los últimos 3 meses cerrados)
 *     ÷ margen de contribución (lo que queda de cada peso vendido después de
 *       IVA, materiales, comisiones, franquicia y gastos que suben con las ventas)
 *   Las comisiones del pool no son lineales (solo existen sobre la meta);
 *   tratarlas como un % promedio sirve para un mes típico.
 * - Días de cartera (DSO): lo que deben los clientes ÷ lo que se vende en un
 *   día (promedio de los últimos 90). Cuánto se demora en entrar la plata.
 * - Cobertura de caja: con el saldo de hoy, cuántos meses de salidas aguanta.
 */
class Indicadores
{
    public static function delMes(string $mes, array $er, ?array $anterior = null): array
    {
        $prop = Proyeccion::proporciones();

        // Punto de equilibrio.
        $mc = $prop['margen_contribucion'];
        $cf = (float) $prop['costos_fijos_promedio'];
        $pe = $mc && $mc > 0 ? round($cf / $mc) : null;
        [$corridos, $totales] = Proyeccion::diasHabiles($mes);
        $restantes = $mes === Periodo::mesActual() ? max(0, $totales - $corridos) : 0;
        $faltan = $pe !== null ? max(0, $pe - $er['ventas']) : null;

        // Cartera y días de cartera.
        $cartera = FuenteVentas::carteraPorAntiguedad();
        $actual = Periodo::mesActual();
        $ventas90 = array_sum(array_column(
            FuenteVentas::vendidoPorMes(Periodo::sumarMeses($actual, -2), $actual), 'vendido'));
        $dso = $ventas90 > 0 ? round($cartera['total'] / ($ventas90 / 90)) : null;

        // Cobertura de caja.
        $saldo = FlujoDeCaja::saldoActual();
        $salidasMes = self::salidasPromedio();
        $cobertura = $saldo !== null && $salidasMes > 0 ? round($saldo / $salidasMes, 1) : null;

        $cfg = ConfigFinanzas::todo();
        $semaforo = self::semaforo($er, $cfg, $dso, $cobertura, $pe);

        return [
            'punto_equilibrio' => [
                'ventas_necesarias' => $pe,
                'costos_fijos'      => $cf,
                'margen_contribucion' => $mc,
                'avance'            => $pe ? round($er['ventas'] / $pe, 3) : null,
                'faltan'            => $faltan,
                'por_dia_habil'     => $faltan && $restantes > 0 ? round($faltan / $restantes) : null,
                'dias_habiles_restantes' => $restantes,
                'meses_base'        => $prop['meses'],
            ],
            'cartera'   => $cartera + ['dias' => $dso],
            'caja'      => ['saldo' => $saldo, 'salidas_promedio' => round($salidasMes), 'meses_cobertura' => $cobertura],
            'recaudo'   => $er['ventas'] > 0 ? round($er['cobrado'] / $er['ventas'], 3) : null,
            'variacion' => $anterior ? [
                'ventas'    => self::variacion($er['ventas'], $anterior['ventas']),
                'utilidad'  => self::variacion($er['utilidad_operativa'], $anterior['utilidad_operativa']),
                'nomina'    => self::variacion($er['nomina']['total'], $anterior['nomina']['total']),
                'gastos'    => self::variacion($er['gastos']['total'], $anterior['gastos']['total']),
            ] : null,
            'semaforo'  => $semaforo,
        ];
    }

    /** Las salidas de caja de un mes típico (promedio de los últimos 3 cerrados). */
    private static function salidasPromedio(): float
    {
        $actual = Periodo::mesActual();
        $meses = FlujoDeCaja::meses(Periodo::sumarMeses($actual, -3), Periodo::sumarMeses($actual, -1))['meses'];
        $con = array_filter($meses, fn ($m) => $m['salidas']['total'] > 0);

        return $con ? array_sum(array_map(fn ($m) => $m['salidas']['total'], $con)) / count($con) : 0.0;
    }

    private static function variacion(float $ahora, float $antes): ?float
    {
        return $antes != 0 ? round(($ahora - $antes) / abs($antes), 3) : null;
    }

    /** @return array<int, array{clave: string, nivel: string, titulo: string, texto: string}> */
    private static function semaforo(array $er, array $cfg, ?float $dso, ?float $cobertura, ?float $pe): array
    {
        $out = [];
        $pct = fn ($x) => $x === null ? '—' : number_format($x * 100, 1, ',', '.') . ' %';
        $pesos = fn ($x) => '$' . number_format((float) $x, 0, ',', '.');

        if ($er['sin_gastos_registrados']) {
            $out[] = ['clave' => 'sin_gastos', 'nivel' => 'atencion', 'titulo' => 'Faltan gastos por registrar',
                'texto' => 'Este mes no tiene gastos cargados: la utilidad sale más alta de lo que es. Registra el arriendo, los servicios y las licencias.'];
        }

        $margen = $er['margenes']['operativo'];
        if ($margen !== null) {
            $umbral = $cfg['umbral_margen_pct'] / 100;
            $out[] = $margen < 0
                ? ['clave' => 'margen', 'nivel' => 'alerta', 'titulo' => 'El mes va en pérdida',
                   'texto' => "Los costos superan los ingresos netos en {$pesos(-$er['utilidad_operativa'])} (margen {$pct($margen)})."]
                : ($margen < $umbral
                    ? ['clave' => 'margen', 'nivel' => 'atencion', 'titulo' => 'Margen operativo bajo',
                       'texto' => "Queda {$pct($margen)} de cada peso vendido; la meta es más de {$pct($umbral)}."]
                    : ['clave' => 'margen', 'nivel' => 'bien', 'titulo' => 'Margen operativo sano',
                       'texto' => "De cada peso que entra (sin IVA) quedan {$pct($margen)} de utilidad."]);
        }

        $nom = $er['margenes']['nomina'];
        if ($nom !== null) {
            $umbral = $cfg['umbral_nomina_pct'] / 100;
            $out[] = $nom > $umbral
                ? ['clave' => 'nomina', 'nivel' => 'atencion', 'titulo' => 'La nómina pesa mucho',
                   'texto' => "La nómina (con aportes y prestaciones) se come el {$pct($nom)} de lo vendido sin IVA; el tope que pusiste es {$pct($umbral)}."]
                : ['clave' => 'nomina', 'nivel' => 'bien', 'titulo' => 'Nómina en su lugar',
                   'texto' => "La nómina es el {$pct($nom)} de lo vendido sin IVA."];
        }

        if ($pe && ! $er['parcial'] && $er['ventas'] < $pe) {
            $out[] = ['clave' => 'equilibrio', 'nivel' => 'alerta', 'titulo' => 'No se llegó al punto de equilibrio',
                'texto' => "Se vendieron {$pesos($er['ventas'])} y hacían falta {$pesos($pe)} para cubrir los costos."];
        }

        if ($er['ventas'] > 0 && $er['cobrado'] / $er['ventas'] < 0.6) {
            $out[] = ['clave' => 'recaudo', 'nivel' => 'atencion', 'titulo' => 'Se está cobrando poco',
                'texto' => 'Entró menos del 60 % de lo vendido: la cartera crece. Revisa los saldos pendientes.'];
        }

        if ($dso !== null && $dso > 60) {
            $out[] = ['clave' => 'cartera', 'nivel' => 'atencion', 'titulo' => 'La plata se demora en entrar',
                'texto' => "Lo que deben los clientes equivale a {$dso} días de ventas."];
        }

        if ($cobertura !== null) {
            $out[] = $cobertura < 1
                ? ['clave' => 'caja', 'nivel' => 'alerta', 'titulo' => 'Caja apretada',
                   'texto' => "Con el saldo de hoy alcanza para {$cobertura} meses de salidas."]
                : ['clave' => 'caja', 'nivel' => $cobertura < 2 ? 'atencion' : 'bien', 'titulo' => 'Cobertura de caja',
                   'texto' => "Con el saldo de hoy alcanza para {$cobertura} meses de salidas."];
        }

        $vencidas = ObligacionesRecurrentes::pendientes(0)->where('estado', 'vencida');
        if ($vencidas->count()) {
            $out[] = ['clave' => 'vencidas', 'nivel' => 'alerta', 'titulo' => 'Pagos vencidos',
                'texto' => $vencidas->count() . ' gasto(s) fijo(s) vencido(s) sin pagar por ' . $pesos($vencidas->sum('monto')) . '.'];
        }

        if ($er['costo_produccion']['cobertura'] < 0.5 && $er['ventas'] > 0 && ConfigFinanzas::get('usar_costo_fichas')) {
            $out[] = ['clave' => 'fichas', 'nivel' => 'info', 'titulo' => 'Costo de materiales con poca base',
                'texto' => 'Solo ' . $pct($er['costo_produccion']['cobertura']) . ' de lo vendido tiene ficha técnica con costo: el margen bruto es una aproximación.'];
        }

        $orden = ['alerta' => 0, 'atencion' => 1, 'info' => 2, 'bien' => 3];
        usort($out, fn ($a, $b) => $orden[$a['nivel']] <=> $orden[$b['nivel']]);

        return $out;
    }
}
