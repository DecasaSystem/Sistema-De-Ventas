<?php

namespace App\Services\Finanzas;

use App\Models\GastoRecurrente;
use App\Services\NominaLiquidador;
use App\Support\FestivosColombia;
use Carbon\Carbon;

/**
 * Lo que viene. Dos partes muy distintas, y la pantalla las separa:
 *
 * 1. Lo que YA SE SABE (determinístico): la nómina de cada ciclo con los
 *    sueldos de hoy y los porcentajes que vayan a regir, los gastos fijos de
 *    las plantillas, las comisiones que quedan disponibles el 20.
 * 2. Las VENTAS (estadística), con su banda de error:
 *    - con menos de 6 meses de historia: promedio ponderado de los últimos 3
 *      (50 % / 30 % / 20 %);
 *    - con 6 o más: tendencia lineal por mínimos cuadrados
 *        ŷ(t) = a + b·t,  b = Σ(t−t̄)(y−ȳ) / Σ(t−t̄)²,  a = ȳ − b·t̄
 *    - banda: ± 1 error estándar de los residuos, s = √(Σe² / (n−2)).
 *    Decasa tiene datos desde mayo de 2026: todavía no hay un año para medir
 *    la temporada (diciembre, la prima de junio). Se dice en pantalla.
 *
 * El mes en curso se proyecta "al cierre" con el ritmo por día hábil (lunes a
 * sábado sin festivos: las tiendas no abren en festivos).
 */
class Proyeccion
{
    public static function ventas(int $mesesFuturos = 3): array
    {
        $actual = Periodo::mesActual();
        // Dos años: la tendencia mira los últimos 12 meses cerrados y la
        // temporada compara con el mismo mes del año anterior.
        $desde  = Periodo::sumarMeses($actual, -24);
        $hist   = FuenteVentas::vendidoPorMes($desde, $actual);

        // Meses cerrados, desde el primero con ventas (antes no existía el sistema).
        $todos = [];
        foreach (Periodo::meses($desde, Periodo::sumarMeses($actual, -1)) as $m) {
            if (! $todos && empty($hist[$m])) continue;
            $todos[$m] = (float) ($hist[$m]['vendido'] ?? 0);
        }
        $cerrados = array_slice($todos, -12, null, true);

        $modelo = self::modelo(array_values($cerrados));
        $conTemporada = false;

        // El mes en curso al cierre, por el ritmo de los días hábiles.
        $vendidoHoy = (float) ($hist[$actual]['vendido'] ?? 0);
        [$corridos, $totales] = self::diasHabiles($actual);
        $cierre = $corridos > 0 ? round($vendidoHoy / $corridos * $totales) : $vendidoHoy;

        $futuro = [];
        for ($k = 1; $k <= $mesesFuturos; $k++) {
            $mesF = Periodo::sumarMeses($actual, $k);
            $base = max(0.0, $modelo['predecir'](count($cerrados) + $k));
            // La temporada: si hace un año ese mes se vendió 30 % más que el
            // promedio de su año, se proyecta 30 % más (diciembre, la prima).
            $indice = self::indiceEstacional($todos, $mesF);
            if ($indice !== null) {
                $base *= $indice;
                $conTemporada = true;
            }
            $base = round($base);
            $futuro[] = [
                'mes'       => $mesF,
                'nombre'    => Periodo::nombre($mesF),
                'base'      => $base,
                'pesimista' => max(0.0, round($base - $modelo['error'])),
                'optimista' => round($base + $modelo['error']),
                'temporada' => $indice !== null ? round($indice, 3) : null,
            ];
        }

        return [
            'historia' => collect($cerrados)->map(fn ($v, $m) => ['mes' => $m, 'nombre' => Periodo::nombre($m), 'vendido' => $v])->values()->all(),
            'actual'   => [
                'mes'               => $actual,
                'vendido_hasta_hoy' => $vendidoHoy,
                'cierre_estimado'   => $cierre,
                'dias_habiles'      => $totales,
                'dias_corridos'     => $corridos,
            ],
            'futuro'         => $futuro,
            'metodo'         => $modelo['metodo'] . ($conTemporada ? '_con_temporada' : ''),
            'meses_historia' => count($todos),
            'error'          => round($modelo['error']),
            'pendiente'      => $modelo['pendiente'],
        ];
    }

    /**
     * El estado de resultados de los próximos meses: ventas en tres escenarios
     * y los costos con lo que se sabe (nómina, gastos fijos) más las
     * proporciones de los últimos tres meses cerrados (IVA, materiales,
     * comisiones, franquicia).
     */
    public static function resultados(int $mesesFuturos = 3): array
    {
        $actual = Periodo::mesActual();
        $ventas = self::ventas($mesesFuturos);
        $prop   = self::proporciones();

        $ultimo = Periodo::sumarMeses($actual, $mesesFuturos);
        [, $fin] = Periodo::limites($ultimo);
        $nomina   = FuenteNomina::proyeccion($fin)['meses'];
        $recurrentes = self::gastosRecurrentesPorMes($actual, $ultimo);

        $filas = [];
        $escenarios = [['mes' => $actual, 'nombre' => Periodo::nombre($actual),
            'base' => $ventas['actual']['cierre_estimado'],
            'pesimista' => $ventas['actual']['cierre_estimado'],
            'optimista' => $ventas['actual']['cierre_estimado'], 'en_curso' => true]];
        foreach ($ventas['futuro'] as $f) $escenarios[] = $f + ['en_curso' => false];

        foreach ($escenarios as $e) {
            $mes = $e['mes'];
            $nom = (float) ($nomina[$mes]['costo'] ?? 0);
            $gas = max((float) ($recurrentes[$mes] ?? 0), $prop['gastos_promedio']);

            $calc = function (float $vendido) use ($prop, $nom, $gas) {
                $netas = $vendido * (1 - $prop['iva']);
                $varios = $vendido * ($prop['costo'] + $prop['comisiones'] + $prop['franquicia']);

                return round($netas - $varios - $nom - $gas);
            };

            $filas[] = [
                'mes'        => $mes,
                'nombre'     => $e['nombre'],
                'en_curso'   => $e['en_curso'],
                'ventas'     => ['base' => $e['base'], 'pesimista' => $e['pesimista'], 'optimista' => $e['optimista']],
                'nomina'     => round($nom),
                'gastos'     => round($gas),
                'gastos_recurrentes' => round((float) ($recurrentes[$mes] ?? 0)),
                'utilidad'   => [
                    'base'      => $calc((float) $e['base']),
                    'pesimista' => $calc((float) $e['pesimista']),
                    'optimista' => $calc((float) $e['optimista']),
                ],
            ];
        }

        return ['meses' => $filas, 'proporciones' => $prop, 'ventas' => $ventas];
    }

    /**
     * El flujo de caja de las próximas semanas: con un saldo de partida, en
     * qué semana se quedaría en rojo (la quincena y el arriendo juntos, por
     * ejemplo). Las salidas tienen fecha exacta (nómina, plantillas,
     * comisiones del 20); las entradas son el ritmo de cobro esperado.
     */
    public static function flujoSemanal(int $semanas = 13): array
    {
        $hoy    = Periodo::hoy();
        $inicio = $hoy->copy()->startOfWeek(Carbon::MONDAY);
        $fin    = $inicio->copy()->addWeeks($semanas)->subDay();
        $prop   = self::proporciones();
        $ventas = self::ventas(4);

        // Ventas esperadas por mes (cierre del actual + base de los siguientes).
        $ventaMes = [$ventas['actual']['mes'] => (float) $ventas['actual']['cierre_estimado']];
        foreach ($ventas['futuro'] as $f) $ventaMes[$f['mes']] = (float) $f['base'];

        $filas = [];
        for ($i = 0; $i < $semanas; $i++) {
            $ini = $inicio->copy()->addWeeks($i);
            $filas[$i] = [
                'inicio' => $ini->toDateString(), 'fin' => $ini->copy()->addDays(6)->toDateString(),
                'entradas' => 0.0, 'nomina' => 0.0, 'comisiones' => 0.0, 'gastos' => 0.0,
            ];
        }
        $semanaDe = function (string $fecha) use ($inicio, $semanas, $hoy): ?int {
            $d = Carbon::parse($fecha, Periodo::TZ);
            // Lo atrasado se paga ya: va en la primera semana.
            if ($d->lessThan($hoy)) return 0;
            $i = intdiv((int) $inicio->diffInDays($d), 7);

            return $i < $semanas ? $i : null;
        };

        // Entradas: lo que se vende cada día × lo que se suele cobrar de lo vendido.
        for ($d = $hoy->copy(); $d->lessThanOrEqualTo($fin); $d->addDay()) {
            $i = $semanaDe($d->toDateString());
            if ($i === null) continue;
            $mes = $d->format('Y-m');
            $filas[$i]['entradas'] += ($ventaMes[$mes] ?? 0) / $d->daysInMonth * $prop['recaudo'];
            // Los gastos que no son de plantilla, repartidos parejo.
            $filas[$i]['gastos'] += $prop['gastos_sueltos'] / $d->daysInMonth;
        }

        // Nómina: lo atrasado y cada ciclo en su fecha de pago (neto + aportes).
        foreach (NominaLiquidador::pendientes(\App\Services\CicloNomina::hoy()) as $p) {
            $filas[0]['nomina'] += (float) $p['total'] + (float) $p['descuento_seguridad_social'] + (float) $p['costo_empleador']['aportes'];
        }
        foreach (FuenteNomina::proyeccion($fin)['pagos'] as $p) {
            $i = $semanaDe($p['fecha_pago']);
            if ($i !== null && $p['fecha_pago'] >= $hoy->toDateString()) $filas[$i]['nomina'] += $p['neto'] + $p['aportes'];
        }

        // Comisiones ya causadas en su fecha; las de los meses que vienen, el 20 del siguiente.
        foreach (FuenteComisiones::porPagar() as $c) {
            $i = $semanaDe($c['fecha']);
            if ($i !== null) $filas[$i]['comisiones'] += $c['monto'];
        }
        foreach ($ventaMes as $mes => $v) {
            $pago = Carbon::parse(Periodo::sumarMeses($mes, 1) . '-20', Periodo::TZ);
            $i = $semanaDe($pago->toDateString());
            if ($i !== null && $pago->greaterThan($hoy)) $filas[$i]['comisiones'] += $v * $prop['comisiones'];
        }

        // Facturas de proveedores en su vencimiento (las vencidas, ya).
        foreach (FuenteGastos::facturasPorPagar() as $f) {
            $i = $semanaDe($f['vence']);
            if ($i !== null) $filas[$i]['gastos'] += $f['saldo'];
        }

        // Prima, cesantías e intereses en su fecha límite (lo vencido sin pagar, ya).
        foreach (\App\Services\Prestaciones::vencimientos($hoy->copy()->subDays(60), $fin) as $v) {
            $i = $semanaDe($v['fecha']);
            if ($i !== null) $filas[$i]['nomina'] += $v['monto'];
        }

        // Gastos de plantilla en su fecha.
        foreach (ObligacionesRecurrentes::pendientes((int) $hoy->diffInDays($fin), $hoy) as $o) {
            $i = $semanaDe($o['vence']);
            if ($i !== null) $filas[$i]['gastos'] += $o['monto'];
        }

        $saldo = FlujoDeCaja::saldoActual();
        $primeraNegativa = null;
        $out = [];
        foreach ($filas as $i => $f) {
            $salidas = $f['nomina'] + $f['comisiones'] + $f['gastos'];
            $neto = $f['entradas'] - $salidas;
            if ($saldo !== null) {
                $saldo += $neto;
                if ($saldo < 0 && $primeraNegativa === null) $primeraNegativa = $f['inicio'];
            }
            $out[] = [
                'inicio'   => $f['inicio'],
                'fin'      => $f['fin'],
                'entradas' => round($f['entradas']),
                'salidas'  => [
                    'total'      => round($salidas),
                    'nomina'     => round($f['nomina']),
                    'comisiones' => round($f['comisiones']),
                    'gastos'     => round($f['gastos']),
                ],
                'neto'     => round($neto),
                'saldo'    => $saldo !== null ? round($saldo) : null,
            ];
        }

        return [
            'semanas'               => $out,
            'saldo_inicial'         => FlujoDeCaja::saldoActual(),
            'primera_semana_negativa' => $primeraNegativa,
            'recaudo'               => $prop['recaudo'],
        ];
    }

    /**
     * Las proporciones de los últimos tres meses cerrados (o los que haya):
     * qué parte de lo vendido se va en IVA, materiales, comisiones y
     * franquicia, cuánto se cobra de lo vendido y cuánto se gasta al mes.
     */
    public static function proporciones(): array
    {
        return self::$proporciones ??= self::calcularProporciones();
    }

    private static ?array $proporciones = null;

    public static function olvidarCache(): void
    {
        self::$proporciones = null;
    }

    private static function calcularProporciones(): array
    {
        $actual = Periodo::mesActual();
        $er = EstadoResultados::meses(Periodo::sumarMeses($actual, -3), Periodo::sumarMeses($actual, -1))['meses'];
        $conVentas = array_values(array_filter($er, fn ($f) => $f['ventas'] > 0));
        if (! $conVentas) {
            // Sin historia: el mes en curso, o nada.
            $conVentas = array_values(array_filter([EstadoResultados::mes($actual)], fn ($f) => $f['ventas'] > 0));
        }

        $v = array_sum(array_column($conVentas, 'ventas'));
        $suma = fn (string $ruta) => array_sum(array_map(fn ($f) => data_get($f, $ruta, 0), $conVentas));
        $n = max(1, count($conVentas));
        $ratio = fn (float $x) => $v > 0 ? round($x / $v, 4) : 0.0;

        $recaudo = $ratio($suma('cobrado'));

        return [
            'meses'           => count($conVentas),
            'iva'             => $ratio($suma('iva')),
            'costo'           => $ratio($suma('costo_produccion.monto')),
            'comisiones'      => $ratio($suma('comisiones.total')),
            'franquicia'      => $ratio($suma('financieros.franquicia')),
            // Lo cobrado contra lo vendido, acotado: un mes raro no puede
            // proyectar que se cobra el triple de lo que se vende.
            'recaudo'         => $v > 0 ? min(1.2, max(0.5, $recaudo)) : 0.8,
            'gastos_promedio' => round(($suma('gastos.total') + $suma('financieros.bancarios')) / $n),
            'gastos_sueltos'  => round(max(0, ($suma('gastos.total') + $suma('financieros.bancarios')) / $n
                                 - self::recurrentesPromedio())),
            'margen_contribucion' => $v > 0 ? round(1 - ($suma('variables_con_ventas') + $suma('iva')) / $v, 4) : null,
            // Cuánto le cuesta a la empresa cada peso de sueldo (con aportes y
            // prestaciones), sacado de la nómina real: así el simulador no
            // tiene un porcentaje fijo que se desactualice con la ley.
            'factor_nomina' => $suma('nomina.sueldos') > 0
                ? round($suma('nomina.total') / $suma('nomina.sueldos'), 3) : null,
            // Lo que cuesta abrir las puertas cada mes, se venda o no: nómina,
            // gastos que no suben con las ventas y bancarios. Promedio mensual.
            'costos_fijos_promedio' => round((
                $suma('nomina.total') + $suma('gastos.total') + $suma('financieros.total')
                - ($suma('variables_con_ventas') - $suma('costo_produccion.monto') - $suma('comisiones.total'))
            ) / $n),
        ];
    }

    /** Lo que suman las plantillas en cada mes (repartido por lo que cubren). */
    public static function gastosRecurrentesPorMes(string $desde, string $hasta): array
    {
        [$ini] = Periodo::limites($desde);
        [, $fin] = Periodo::limites($hasta);
        $plantillas = GastoRecurrente::where('activo', true)->get();
        $sugeridos  = ObligacionesRecurrentes::montosSugeridos($plantillas);
        $meses = array_flip(Periodo::meses($desde, $hasta));

        $out = [];
        foreach ($plantillas as $g) {
            // Un poco antes del rango: un anual prorrateado de marzo cubre octubre.
            foreach (ObligacionesRecurrentes::periodos($g, $ini->copy()->subYear(), $fin) as $p) {
                foreach (Periodo::repartirGasto($p['cubre_desde'], $p['cubre_hasta']) as $mes => $fr) {
                    if (isset($meses[$mes])) $out[$mes] = ($out[$mes] ?? 0) + $sugeridos[$g->id] * $fr;
                }
            }
        }

        return $out;
    }

    private static function recurrentesPromedio(): float
    {
        $actual = Periodo::mesActual();
        $r = self::gastosRecurrentesPorMes(Periodo::sumarMeses($actual, -3), Periodo::sumarMeses($actual, -1));

        return $r ? array_sum($r) / 3 : 0.0;
    }

    /**
     * Cuánto se aparta un mes de su año: lo vendido ese mismo mes hace un año
     * dividido por el promedio de los 12 meses alrededor (6 antes, 5 después).
     * Sin ese mes o con menos de 9 meses alrededor, no hay base: null. Se
     * acota entre 0,5 y 2 para que un mes raro no dispare la proyección.
     *
     * @param array<string, float> $serie [mes => vendido]
     */
    public static function indiceEstacional(array $serie, string $mes): ?float
    {
        $haceUnAnio = Periodo::sumarMeses($mes, -12);
        if (! isset($serie[$haceUnAnio]) || $serie[$haceUnAnio] <= 0) return null;

        $valores = [];
        for ($k = -6; $k <= 5; $k++) {
            $m = Periodo::sumarMeses($haceUnAnio, $k);
            if (isset($serie[$m])) $valores[] = $serie[$m];
        }
        if (count($valores) < 9) return null;
        $promedio = array_sum($valores) / count($valores);

        return $promedio > 0 ? max(0.5, min(2.0, $serie[$haceUnAnio] / $promedio)) : null;
    }

    /**
     * Ajusta el modelo a la serie y devuelve con qué predecir.
     *
     * @param float[] $y
     */
    public static function modelo(array $y): array
    {
        $n = count($y);

        if ($n === 0) {
            return ['metodo' => 'sin_historia', 'predecir' => fn ($t) => 0.0, 'error' => 0.0, 'pendiente' => null];
        }

        if ($n >= 6) {
            $tMedia = ($n - 1) / 2;
            $yMedia = array_sum($y) / $n;
            $num = $den = 0.0;
            foreach ($y as $t => $v) {
                $num += ($t - $tMedia) * ($v - $yMedia);
                $den += ($t - $tMedia) ** 2;
            }
            $b = $den > 0 ? $num / $den : 0.0;
            $a = $yMedia - $b * $tMedia;
            $sse = 0.0;
            foreach ($y as $t => $v) $sse += ($v - ($a + $b * $t)) ** 2;
            $s = sqrt($sse / max(1, $n - 2));

            // t empieza en 0 con el primer mes cerrado; el mes en curso es n y
            // el k-ésimo mes que viene, n + k (así lo llama ventas()).
            return ['metodo' => 'tendencia', 'predecir' => fn ($t) => $a + $b * $t, 'error' => $s, 'pendiente' => round($b)];
        }

        $ultimos = array_slice($y, -3);
        $pesos = match (count($ultimos)) { 3 => [0.2, 0.3, 0.5], 2 => [0.4, 0.6], default => [1.0] };
        $base = 0.0;
        foreach ($ultimos as $i => $v) $base += $v * $pesos[$i];

        $media = array_sum($y) / $n;
        $s = $n >= 2 ? sqrt(array_sum(array_map(fn ($v) => ($v - $media) ** 2, $y)) / ($n - 1)) : $base * 0.15;

        return ['metodo' => 'promedio_ponderado', 'predecir' => fn ($t) => $base, 'error' => $s, 'pendiente' => null];
    }

    /** Días hábiles de venta del mes (lunes a sábado sin festivos): [corridos hasta hoy, totales]. */
    public static function diasHabiles(string $mes): array
    {
        [$ini, $fin] = Periodo::limites($mes);
        $hoy = Periodo::hoy();
        $corridos = $totales = 0;
        for ($d = $ini->copy(); $d->lessThanOrEqualTo($fin); $d->addDay()) {
            if ($d->isSunday() || FestivosColombia::esFestivo($d)) continue;
            $totales++;
            if ($d->lessThanOrEqualTo($hoy)) $corridos++;
        }

        return [$corridos, $totales];
    }
}
