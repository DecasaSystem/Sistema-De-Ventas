<?php

namespace App\Services;

use App\Models\OrdenItem;
use App\Models\ProduccionPaso;
use App\Models\TipoProceso;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Lo que hizo cada trabajador del taller en un periodo, sacado de Producción.
 *
 * Hasta ahora, al final del mes cada trabajador llenaba a mano una hoja con lo
 * que había hecho, de memoria, y nada impedía que se pusiera de más (usuario,
 * 2026-11-27). Producción ya sabe quién trabajó en cada paso
 * (`paso_trabajadores`): esto lo junta por persona para imprimirle su hoja.
 * Solo lee; no toca plata ni la bonificación por producción de Nómina.
 *
 * Reglas del usuario (2026-11-27):
 *  - cuenta cada paso TERMINADO en el periodo, para cada persona que figura en
 *    él, completo a cada uno aunque hayan sido varios;
 *  - un paso devuelto y rehecho cuenta una vez, para quien lo termina: es lo
 *    que ya guarda el sistema (al devolver, el paso vuelve a pendiente y al
 *    cerrarlo otra vez quedan los que se pusieron al cerrar);
 *  - garantías y restauraciones también: "lo que importa es lo que ellos
 *    hicieron", no para quién era (orden, Reserva o tienda no se muestra);
 *  - el paso Despacho no es trabajo del taller y no sale.
 *
 * Los pasos de antes del 2026-08-25 solo tienen nombres escritos a mano y no
 * se pueden pegar a nadie: no salen.
 */
class TrabajoDelTaller
{
    /**
     * El periodo en días de Bogotá → límites en UTC para comparar con
     * `completado_at` (la base guarda UTC: un paso cerrado el 31 a las 8 p. m.
     * es del 31, no del 1).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function limitesUtc(string $desde, string $hasta): array
    {
        return [
            Carbon::parse($desde, RangoFechas::TZ_NEGOCIO)->startOfDay()->utc(),
            Carbon::parse($hasta, RangoFechas::TZ_NEGOCIO)->endOfDay()->utc(),
        ];
    }

    /**
     * Por persona: su nombre y los renglones agrupados.
     *
     * Se juntan los iguales (mismo paso + mismo producto + misma versión +
     * misma marca) en un renglón con la cantidad sumada: la hoja es tamaño
     * carta y el usuario la quiere corta ("si es mucho, 2 hojas").
     *
     * @return Collection<int, array{usuario_id:int, nombre:string, piezas:float, trabajos:int, grupos:array}>
     */
    public static function porPersona(string $desde, string $hasta, ?int $usuarioId = null): Collection
    {
        [$ini, $fin] = self::limitesUtc($desde, $hasta);

        $soloEl = fn ($q) => $usuarioId ? $q->where('usuario_id', $usuarioId) : $q;

        $pasos = ProduccionPaso::query()
            ->where('estado', 'completado')
            ->where('tipo_proceso', '!=', ProduccionPaso::DESPACHO)
            ->whereBetween('completado_at', [$ini, $fin])
            ->whereHas('participantes', $soloEl)
            ->with([
                'participantes' => fn ($q) => $soloEl($q)->with('usuario:id,nombre'),
                'produccion.ordenItem.producto:id,nombre',
                'produccion.producto:id,nombre',
                'produccion.variante:id,marca,marca_tela,nombre_color,medida',
                'produccion.comboConfig.opcion:id,nombre',
            ])
            ->orderBy('completado_at')
            ->get();

        OrdenItem::precargarVariantes($pasos->pluck('produccion.ordenItem'));

        $personas = [];

        foreach ($pasos as $paso) {
            $prod = $paso->produccion;
            if (! $prod) {
                continue;
            }

            $item     = $prod->ordenItem;
            $producto = $prod->productoNombre() ?? 'Producto';
            $version  = $item ? $item->variante_texto : self::versionDeReserva($prod);
            $cantidad = (float) ($item?->cantidad ?? $prod->cantidad ?? 1) ?: 1;
            $marca    = ($paso->garantia_id ?? null)
                ? 'garantía'
                : (($paso->esRestauracion() || $item?->es_restauracion) ? 'restauración' : null);
            $proceso  = TipoProceso::nombreDe($paso->tipo_proceso);

            foreach ($paso->participantes as $p) {
                if (! $p->usuario) {
                    continue;
                }
                $uid = $p->usuario_id;
                $personas[$uid] ??= ['usuario_id' => $uid, 'nombre' => $p->usuario->nombre, 'grupos' => []];

                $clave = mb_strtolower("{$paso->tipo_proceso}|{$producto}|{$version}|{$marca}");
                $grupo = &$personas[$uid]['grupos'][$paso->tipo_proceso];
                $grupo ??= ['proceso' => $proceso, 'renglones' => []];
                $grupo['renglones'][$clave] ??= [
                    'producto' => $producto,
                    'version'  => $version,
                    'marca'    => $marca,
                    'cantidad' => 0,
                ];
                $grupo['renglones'][$clave]['cantidad'] += $cantidad;
                unset($grupo);
            }
        }

        return collect($personas)
            ->map(function (array $persona) {
                $grupos = collect($persona['grupos'])
                    ->map(fn ($g) => [
                        'proceso'   => $g['proceso'],
                        'renglones' => collect($g['renglones'])
                            ->sortBy(fn ($r) => mb_strtolower($r['producto'] . ' ' . $r['version']))
                            ->values()->all(),
                    ])
                    ->sortBy(fn ($g) => mb_strtolower($g['proceso']))
                    ->values();

                $renglones = $grupos->flatMap(fn ($g) => $g['renglones']);

                return [
                    'usuario_id' => $persona['usuario_id'],
                    'nombre'     => $persona['nombre'],
                    'piezas'     => (float) $renglones->sum('cantidad'),
                    'trabajos'   => $renglones->count(),
                    'grupos'     => $grupos->all(),
                ];
            })
            ->sortBy(fn ($p) => mb_strtolower($p['nombre']))
            ->values();
    }

    /** Versión de una pieza para la Reserva (no tiene orden_item). */
    private static function versionDeReserva($prod): ?string
    {
        if (($prod->variante_detalle ?? '') !== '') {
            return $prod->variante_detalle;
        }
        $partes = [];
        if ($v = $prod->variante) {
            $partes = array_filter([$v->marca, $v->marca_tela, $v->nombre_color, $v->medida]);
        }
        if ($op = $prod->comboConfig?->opcion?->nombre) {
            $partes[] = $op;
        }

        return $partes ? implode(' · ', $partes) : null;
    }
}
