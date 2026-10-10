<?php

namespace App\Services\Finanzas;

use App\Models\Orden;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ventas y cobros por mes, con el MISMO criterio que Reportes
 * (StatsController::kpis): lo vendido son las órdenes creadas en el mes sin
 * cotizaciones, borradores ni canceladas (Orden::ESTADOS_FUERA_DE_REPORTES);
 * lo cobrado son los pagos del mes de esas mismas órdenes (ya resta los
 * reembolsos, que son pagos negativos). Si Finanzas y Reportes dieran cifras
 * distintas para el mismo mes, la gente dejaría de creerle a las dos.
 * Prueba: FinanzasCuadraConReportesTest.
 *
 * Todo en dos consultas por rango (agrupadas por mes en hora de Bogotá), no
 * una por mes: cada consulta a Aiven cuesta ~200 ms.
 */
class FuenteVentas
{
    /**
     * @return array<string, array> [mes => vendido, iva, netas, ordenes, por_tipo]
     */
    public static function vendidoPorMes(string $desde, string $hasta, ?int $tiendaId = null): array
    {
        return Periodo::porMesRecordado("vendido:$tiendaId", $desde, $hasta,
            fn ($d, $h) => self::cargarVendido($d, $h, $tiendaId));
    }

    private static function cargarVendido(string $desde, string $hasta, ?int $tiendaId): array
    {
        $divisor = ConfigFinanzas::divisorIva();
        // La FV2 "sin descontar IVA" no trae IVA adentro (decisión de cuando se
        // creó esa marca: su comisión tampoco se divide por 1,19).
        $sinIva = ConfigFinanzas::get('fv2_sin_iva_no_gravada') && self::hayColumna('sin_descontar_iva')
            ? 'COALESCE(o.sin_descontar_iva, 0) = 1'
            : '1 = 0';

        $filas = DB::table('ordenes as o')
            ->whereBetween('o.created_at', Periodo::rangoUtc($desde, $hasta))
            ->whereNotIn('o.estado', Orden::ESTADOS_FUERA_DE_REPORTES)
            ->when($tiendaId, fn ($q) => $q->where('o.tienda_id', $tiendaId))
            ->selectRaw(Periodo::sqlMes('o.created_at') . ' AS mes')
            ->selectRaw('COUNT(*) AS ordenes, SUM(o.valor_total) AS vendido')
            ->selectRaw("SUM(CASE WHEN {$sinIva} THEN o.valor_total ELSE 0 END) AS no_gravado")
            ->selectRaw(Orden::selectMontosPorTipo('o.valor_total'))
            ->groupBy('mes')
            ->get();

        $out = [];
        foreach ($filas as $f) {
            $vendido   = (float) $f->vendido;
            $noGravado = (float) $f->no_gravado;
            $gravado   = $vendido - $noGravado;
            $iva       = round($gravado - $gravado / $divisor);

            $out[$f->mes] = [
                'vendido'  => $vendido,
                'iva'      => $iva,
                'netas'    => $vendido - $iva,
                'ordenes'  => (int) $f->ordenes,
                'por_tipo' => [
                    'venta'        => (float) $f->monto_venta,
                    'restauracion' => (float) $f->monto_restauracion,
                    'fv2'          => (float) $f->monto_fv2,
                ],
            ];
        }

        return $out;
    }

    /**
     * Lo que entró por mes, por medio de pago, y la franquicia del datáfono.
     *
     * @return array<string, array> [mes => cobrado, por_metodo, franquicia]
     */
    public static function cobradoPorMes(string $desde, string $hasta, ?int $tiendaId = null): array
    {
        return Periodo::porMesRecordado("cobrado:$tiendaId", $desde, $hasta,
            fn ($d, $h) => self::cargarCobrado($d, $h, $tiendaId));
    }

    private static function cargarCobrado(string $desde, string $hasta, ?int $tiendaId): array
    {
        $filas = DB::table('pagos as p')
            ->join('ordenes as o', 'o.id', '=', 'p.orden_id')
            ->whereBetween('p.created_at', Periodo::rangoUtc($desde, $hasta))
            ->whereNotIn('o.estado', Orden::ESTADOS_FUERA_DE_REPORTES)
            // Se le acredita a la tienda que recibió la plata (como Reportes).
            ->when($tiendaId, fn ($q) => $q->whereRaw('COALESCE(p.tienda_id, o.tienda_id) = ?', [$tiendaId]))
            ->selectRaw(Periodo::sqlMes('p.created_at') . " AS mes, COALESCE(p.metodo, 'otro') AS metodo, SUM(p.monto) AS total")
            ->groupBy('mes', 'metodo')
            ->get();

        $pct = (float) ConfigFinanzas::get('franquicia_pct') / 100;
        $out = [];
        foreach ($filas as $f) {
            $m = $f->mes;
            $out[$m] ??= ['cobrado' => 0.0, 'por_metodo' => [], 'franquicia' => 0.0];
            $out[$m]['cobrado'] += (float) $f->total;
            $out[$m]['por_metodo'][$f->metodo] = ($out[$m]['por_metodo'][$f->metodo] ?? 0) + (float) $f->total;
            if (in_array($f->metodo, Orden::METODOS_CON_FRANQUICIA, true) && (float) $f->total > 0) {
                $out[$m]['franquicia'] += round((float) $f->total * $pct);
            }
        }

        return $out;
    }

    /**
     * Lo que los clientes deben hoy (cartera viva), por antigüedad de la
     * orden: es lo que se espera cobrar y con qué tanta seguridad.
     *
     * @return array{total: float, tramos: array<string, float>}
     */
    public static function carteraPorAntiguedad(): array
    {
        $hoy = Periodo::hoy();
        $filas = DB::table('v_saldo_ordenes as v')
            ->join('ordenes as o', 'o.id', '=', 'v.orden_id')
            ->where('v.saldo_pendiente', '>', 0)
            ->whereNotIn('o.estado', Orden::ESTADOS_FUERA_DE_REPORTES)
            ->get(['o.created_at', 'v.saldo_pendiente']);

        $tramos = ['0_30' => 0.0, '31_90' => 0.0, 'mas_90' => 0.0];
        foreach ($filas as $f) {
            $dias = \Carbon\Carbon::parse($f->created_at, 'UTC')->setTimezone(Periodo::TZ)->startOfDay()->diffInDays($hoy);
            $tramo = $dias <= 30 ? '0_30' : ($dias <= 90 ? '31_90' : 'mas_90');
            $tramos[$tramo] += (float) $f->saldo_pendiente;
        }

        return ['total' => array_sum($tramos), 'tramos' => $tramos];
    }

    private static array $columnas = [];

    private static function hayColumna(string $col): bool
    {
        return self::$columnas[$col] ??= \App\Support\Esquema::columna('ordenes', $col);
    }

    public static function olvidarCache(): void
    {
        self::$columnas = [];
    }
}
