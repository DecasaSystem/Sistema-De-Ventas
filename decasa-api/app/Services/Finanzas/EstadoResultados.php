<?php

namespace App\Services\Finanzas;

/**
 * El estado de resultados (P&G) de cada mes: ¿se ganó o se perdió plata?
 * Es DEVENGADO: cada peso va al mes al que pertenece, no al mes en que entró o
 * salió la plata (eso es el flujo de caja, otra pantalla).
 *
 *   Ventas (con IVA)                  = "Vendido" de Reportes
 * − IVA incluido                      (se le debe a la DIAN, no es de la empresa)
 * = INGRESOS NETOS
 * − Costo de materiales (estimado)    fichas técnicas, con su cobertura
 * = UTILIDAD BRUTA
 * − Nómina (costo empresa)            sueldos + aportes + prestaciones
 * − Comisiones                        bruta del mes de venta
 * − Gastos (fijos y variables)        prorrateados a su mes; incluye compras del taller
 * − Gastos financieros                franquicia del datáfono/Addi + bancarios
 * = UTILIDAD OPERATIVA
 *
 * Y el mismo resultado por área (producción, ventas, administración,
 * financiero), para ver dónde se va la plata.
 */
class EstadoResultados
{
    /** @return array{meses: array<int, array>, total: array} */
    public static function meses(string $desde, string $hasta): array
    {
        $ventas   = FuenteVentas::vendidoPorMes($desde, $hasta);
        $cobros   = FuenteVentas::cobradoPorMes($desde, $hasta);
        $costo    = CostoProduccion::porMes($desde, $hasta);
        $nomina   = FuenteNomina::porMes($desde, $hasta);
        $comision = FuenteComisiones::causadaPorMes($desde, $hasta);
        $gastos   = FuenteGastos::porMes($desde, $hasta);

        $mesActual   = Periodo::mesActual();
        $primerGasto = FuenteGastos::primerMesConGastos();

        $filas = [];
        foreach (Periodo::meses($desde, $hasta) as $mes) {
            $filas[] = self::fila(
                $mes, $ventas[$mes] ?? null, $cobros[$mes] ?? null, $costo[$mes] ?? null,
                $nomina[$mes] ?? null, $comision[$mes] ?? null, $gastos[$mes] ?? null,
                $mes === $mesActual, ! $primerGasto || $mes < $primerGasto,
            );
        }

        // Los meses cerrados muestran su copia congelada, no lo de hoy.
        $filas = Cierres::aplicar($filas);

        return ['meses' => $filas, 'total' => self::sumarFilas($filas)];
    }

    public static function mes(string $mes): array
    {
        return self::meses($mes, $mes)['meses'][0];
    }

    private static function fila(string $mes, ?array $v, ?array $c, ?array $cp, ?array $n, ?array $co, ?array $g, bool $parcial, bool $sinGastos): array
    {
        $vendido = (float) ($v['vendido'] ?? 0);
        $iva     = (float) ($v['iva'] ?? 0);
        $netas   = (float) ($v['netas'] ?? 0);

        $costoProd   = (float) ($cp['costo'] ?? 0);
        $utilBruta   = $netas - $costoProd;

        $nomCosto    = (float) ($n['costo'] ?? 0);
        $comisiones  = (float) ($co['causada'] ?? 0);
        $franquicia  = (float) ($c['franquicia'] ?? 0);
        $gastosFin   = (float) ($g['por_area']['financiero'] ?? 0);
        $gastosOper  = (float) ($g['devengado'] ?? 0) - $gastosFin;
        $financieros = $franquicia + $gastosFin;

        $utilOper = $utilBruta - $nomCosto - $comisiones - $gastosOper - $financieros;

        $nArea = $n['por_area'] ?? [];
        $gArea = $g['por_area'] ?? [];
        $porArea = [
            'produccion'     => round($costoProd + ($nArea['produccion'] ?? 0) + ($gArea['produccion'] ?? 0)),
            'ventas'         => round(($nArea['ventas'] ?? 0) + $comisiones + ($gArea['ventas'] ?? 0)),
            'administracion' => round(($nArea['administracion'] ?? 0) + ($gArea['administracion'] ?? 0)),
            'financiero'     => round($financieros),
        ];

        $pct = fn (float $x) => $netas > 0 ? round($x / $netas, 4) : null;

        return [
            'mes'               => $mes,
            'nombre'            => Periodo::nombre($mes),
            'parcial'           => $parcial,
            'cerrado'           => false,
            'sin_gastos_registrados' => $sinGastos,
            'ventas'            => round($vendido),
            'ordenes'           => (int) ($v['ordenes'] ?? 0),
            'por_tipo'          => $v['por_tipo'] ?? ['venta' => 0, 'restauracion' => 0, 'fv2' => 0],
            'iva'               => round($iva),
            'ingresos_netos'    => round($netas),
            'costo_produccion'  => [
                'monto'     => round($costoProd),
                'cobertura' => (float) ($cp['cobertura'] ?? 0),
                'estimado'  => true,
            ],
            'utilidad_bruta'    => round($utilBruta),
            'nomina'            => [
                'total'        => round($nomCosto),
                'sueldos'      => round((float) ($n['bruto'] ?? 0)),
                'aportes'      => round((float) ($n['aportes'] ?? 0)),
                'prestaciones' => round((float) ($n['prestaciones'] ?? 0)),
                'otros'        => round((float) ($n['otros'] ?? 0)),
                // Liquidaciones: la indemnización es costo nuevo (lo demás ya estaba provisionado).
                'indemnizaciones' => round((float) ($n['indemnizaciones'] ?? 0)),
                'por_area'     => array_map('round', $nArea),
                'personas'     => (int) ($n['personas'] ?? 0),
                'estimado'     => (bool) ($n['estimado'] ?? false),
            ],
            'comisiones'        => [
                'total'     => round($comisiones),
                'pagada'    => round((float) ($co['pagada'] ?? 0)),
                // Lo no pagado está "al día de hoy" y puede moverse hasta el 20.
                'estimado'  => (float) ($co['por_pagar'] ?? 0) > 0,
            ],
            'gastos'            => [
                'total'         => round($gastosOper),
                'fijos'         => round((float) ($g['fijos'] ?? 0)),
                'variables'     => round((float) ($g['variables'] ?? 0)),
                'compras'       => round((float) ($g['compras'] ?? 0)),
                'por_categoria' => $g['por_categoria'] ?? [],
            ],
            'financieros'       => [
                'total'      => round($financieros),
                'franquicia' => round($franquicia),
                'bancarios'  => round($gastosFin),
            ],
            'utilidad_operativa' => round($utilOper),
            'por_area'          => $porArea,
            'margenes'          => [
                'bruto'     => $pct($utilBruta),
                'operativo' => $pct($utilOper),
                'nomina'    => $pct($nomCosto),
                'comisiones'=> $pct($comisiones),
                'gastos'    => $pct($gastosOper),
            ],
            // Para el punto de equilibrio: lo que se mueve con las ventas.
            'variables_con_ventas' => round($costoProd + $comisiones + $franquicia + (float) ($g['varia_con_ventas'] ?? 0)),
            'cobrado'           => round((float) ($c['cobrado'] ?? 0)),
        ];
    }

    private static function sumarFilas(array $filas): array
    {
        $s = fn (string $ruta) => round(array_sum(array_map(fn ($f) => data_get($f, $ruta, 0), $filas)));
        $netas = $s('ingresos_netos');
        $oper  = $s('utilidad_operativa');

        return [
            'ventas'             => $s('ventas'),
            'iva'                => $s('iva'),
            'ingresos_netos'     => $netas,
            'costo_produccion'   => $s('costo_produccion.monto'),
            'utilidad_bruta'     => $s('utilidad_bruta'),
            'nomina'             => $s('nomina.total'),
            'comisiones'         => $s('comisiones.total'),
            'gastos'             => $s('gastos.total'),
            'financieros'        => $s('financieros.total'),
            'utilidad_operativa' => $oper,
            'margen_operativo'   => $netas > 0 ? round($oper / $netas, 4) : null,
            'cobrado'            => $s('cobrado'),
        ];
    }
}
