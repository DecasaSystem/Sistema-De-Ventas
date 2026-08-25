<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * El filtro de tiempo de los reportes: Hoy, Semana, Mes, Mes anterior, Año, o
 * un rango escrito a mano.
 *
 * Vive acá porque estaba en dos sitios y se separaron. Stats entendía
 * `periodo` y Reportes solo `desde`/`hasta`, así que los apartados servidos
 * por Reportes —Canales entre ellos— se quedaban en su valor por defecto sin
 * importar qué botón se tocara arriba: la pantalla manda `periodo` y solo
 * pone `desde`/`hasta` cuando se elige un rango a mano. El número no se veía
 * mal, se veía plausible, que es peor.
 *
 * Zona horaria del negocio, no la del servidor: la base guarda UTC y en
 * Colombia (UTC−5) todo lo vendido después de las 7 de la noche caería en el
 * día siguiente. "Hoy" se vaciaría a esa hora y una venta del 31 a las 8 p.m.
 * se contaría en el mes entrante.
 */
class RangoFechas
{
    public const TZ_NEGOCIO = 'America/Bogota';

    /**
     * El rango que pide la pantalla, en días del negocio.
     *
     * @return array{0:string,1:string} [desde, hasta] en Y-m-d
     */
    public static function de(Request $request): array
    {
        $hoyCarbon = Carbon::now(self::TZ_NEGOCIO);
        $hoy       = $hoyCarbon->toDateString();

        return match ($request->query('periodo')) {
            'hoy'          => [$hoy, $hoy],
            'semana'       => [$hoyCarbon->copy()->startOfWeek()->toDateString(), $hoy],
            'mes'          => [$hoyCarbon->copy()->startOfMonth()->toDateString(), $hoy],
            'mes_anterior' => [
                $hoyCarbon->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                $hoyCarbon->copy()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            ],
            'anio'         => [$hoyCarbon->copy()->startOfYear()->toDateString(), $hoy],
            // Rango escrito a mano. Sin nada, el mes en curso: es lo que la
            // pantalla trae seleccionado al abrirse.
            default        => [
                $request->query('desde', $hoyCarbon->copy()->startOfMonth()->toDateString()),
                $request->query('hasta', $hoy),
            ],
        };
    }
}
