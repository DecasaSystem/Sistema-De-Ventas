<?php

namespace App\Services\Finanzas;

use App\Models\Orden;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las comisiones vistas desde Finanzas, SIN escribir nada.
 *
 * `ComisionController::resumenDelMes()` no se llama: abre renglones en la base
 * y es caro. No hace falta: la tarea `comisiones:poner-al-dia` (6:30 a. m.)
 * deja `monto_comision` y `estado` al día en todas las filas no pagadas,
 * incluidos los renglones de equipo del pool. Lo no pagado es "al día de hoy
 * a las 6:30" y puede moverse hasta que se pague (la regla del 50 % y la meta).
 *
 * - COSTO (devengado): la comisión BRUTA del mes de venta.
 * - CAJA: lo pagado el día que se pagó (bruta − descuento de anticipos) más
 *   los anticipos que se llevaron ese mes. Sumar "anticipos + bruta" contaría
 *   la misma plata dos veces (AnticiposComision).
 */
class FuenteComisiones
{
    /** @return array<string, array> [mes => causada, pagada, por_pagar, por_tienda] */
    public static function causadaPorMes(string $desde, string $hasta): array
    {
        return Periodo::porMesRecordado('comision-causada', $desde, $hasta, fn ($d, $h) => self::cargarCausada($d, $h));
    }

    private static function cargarCausada(string $desde, string $hasta): array
    {
        $filas = DB::table('comisiones as c')
            ->leftJoin('ordenes as o', 'o.id', '=', 'c.orden_id')
            ->whereBetween('c.mes_venta', [$desde, $hasta])
            // Una cancelada no se comisiona (se borra al cancelar salvo si ya se pagó).
            ->where(fn ($q) => $q->whereNull('o.id')
                ->orWhereNotIn('o.estado', Orden::ESTADOS_FUERA_DE_REPORTES)
                ->orWhere('c.estado', 'pagada'))
            ->selectRaw("c.mes_venta AS mes, c.tienda_id,
                SUM(COALESCE(c.monto_comision, 0)) AS total,
                SUM(CASE WHEN c.estado = 'pagada' THEN COALESCE(c.monto_comision, 0) ELSE 0 END) AS pagada")
            ->groupBy('c.mes_venta', 'c.tienda_id')
            ->get();

        $out = [];
        foreach ($filas as $f) {
            $out[$f->mes] ??= ['causada' => 0.0, 'pagada' => 0.0, 'por_pagar' => 0.0, 'por_tienda' => []];
            $out[$f->mes]['causada']   += (float) $f->total;
            $out[$f->mes]['pagada']    += (float) $f->pagada;
            $out[$f->mes]['por_pagar'] += (float) $f->total - (float) $f->pagada;
            $t = (int) $f->tienda_id;
            $out[$f->mes]['por_tienda'][$t] = ($out[$f->mes]['por_tienda'][$t] ?? 0) + (float) $f->total;
        }

        return $out;
    }

    /** @return array<string, array> [mes => pagado (neto), anticipos, caja] */
    public static function cajaPorMes(string $desde, string $hasta): array
    {
        return Periodo::porMesRecordado('comision-caja', $desde, $hasta, fn ($d, $h) => self::cargarCaja($d, $h));
    }

    private static function cargarCaja(string $desde, string $hasta): array
    {
        $rango = Periodo::rangoUtc($desde, $hasta);
        $out = [];
        $poner = function (string $mes, string $clave, float $v) use (&$out) {
            $out[$mes] ??= ['pagado' => 0.0, 'anticipos' => 0.0, 'caja' => 0.0];
            $out[$mes][$clave] += $v;
        };

        $pagadas = DB::table('comisiones')->where('estado', 'pagada')->whereBetween('fecha_pago', $rango)
            ->selectRaw(Periodo::sqlMes('fecha_pago') . ' AS mes, SUM(COALESCE(monto_comision, 0)) AS total')
            ->groupBy('mes')->get();
        foreach ($pagadas as $f) {
            $poner($f->mes, 'pagado', (float) $f->total);
        }

        if (self::hayAnticipos()) {
            // Lo descontado de anticipos no salió el día del pago: ya había salido.
            $descuentos = DB::table('comision_anticipos as a')->join('comisiones as c', 'c.id', '=', 'a.comision_id')
                ->where('a.tipo', 'descuento')->where('c.estado', 'pagada')
                ->whereBetween('c.fecha_pago', $rango)
                ->selectRaw(Periodo::sqlMes('c.fecha_pago') . ' AS mes, SUM(a.monto) AS total')
                ->groupBy('mes')->get();
            foreach ($descuentos as $f) {
                $poner($f->mes, 'pagado', -(float) $f->total);
            }

            $anticipos = DB::table('comision_anticipos')->where('tipo', 'anticipo')
                ->whereBetween('mes', [$desde, $hasta])
                ->selectRaw('mes, SUM(monto) AS total')->groupBy('mes')->get();
            foreach ($anticipos as $f) {
                $poner($f->mes, 'anticipos', (float) $f->total);
            }
        }

        foreach ($out as $mes => $m) {
            $out[$mes]['caja'] = $m['pagado'] + $m['anticipos'];
        }

        return $out;
    }

    /**
     * Lo que falta pagar, por fecha en que queda disponible (el 20, o el
     * cierre del trimestre). Para el calendario de pagos.
     *
     * @return array<int, array{fecha: string, monto: float, listas: float}>
     */
    public static function porPagar(): array
    {
        return DB::table('comisiones')
            ->whereIn('estado', ['pendiente', 'lista'])
            ->where('monto_comision', '>', 0)
            ->selectRaw("fecha_disponible AS fecha, SUM(monto_comision) AS monto,
                SUM(CASE WHEN estado = 'lista' THEN monto_comision ELSE 0 END) AS listas")
            ->groupBy('fecha_disponible')
            ->orderBy('fecha_disponible')
            ->get()
            ->map(fn ($f) => [
                'fecha'  => substr((string) $f->fecha, 0, 10),
                'monto'  => (float) $f->monto,
                'listas' => (float) $f->listas,
            ])->all();
    }

    private static ?bool $hayAnticipos = null;

    private static function hayAnticipos(): bool
    {
        return self::$hayAnticipos ??= \App\Support\Esquema::tabla('comision_anticipos');
    }

    public static function olvidarCache(): void
    {
        self::$hayAnticipos = null;
    }
}
