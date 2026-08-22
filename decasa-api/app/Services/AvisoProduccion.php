<?php

namespace App\Services;

use App\Models\Orden;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

/**
 * El taller tiene que enterarse cuando editan una orden que está fabricando.
 *
 * El ebanista trabaja con las medidas que le llegaron el día que arrancó. Si
 * después le cambian la tela, el tamaño o le quitan el mueble, sigue armando lo
 * viejo hasta que alguien se lo dice de palabra. Este aviso cierra ese hueco.
 */
class AvisoProduccion
{
    /**
     * Producciones que ya no se pueden cambiar: el mueble salió o se anuló.
     * 'listo' sí cuenta — si le cambian las medidas a algo recién terminado,
     * es justo cuando más urge saberlo.
     */
    private const PRODUCCION_CERRADA = ['entregado', 'cancelado'];

    /** Cambios que le cambian el trabajo a quien está armando el mueble. */
    private const PATRONES_DE_TALLER = [
        '/^item_\d+_(specs|cantidad|producto|eliminado)$/',
        '/^item_nuevo/',
    ];

    public static function afectaAlTaller(array $cambio): bool
    {
        $campo = $cambio['campo'] ?? '';
        foreach (self::PATRONES_DE_TALLER as $patron) {
            if (preg_match($patron, $campo)) return true;
        }
        return false;
    }

    /** ¿Esta orden tiene algo en el taller ahora mismo? */
    public static function tieneProduccionViva(Orden $orden): bool
    {
        return DB::table('produccion')
            ->join('orden_items', 'orden_items.id', '=', 'produccion.orden_item_id')
            ->where('orden_items.orden_id', $orden->id)
            ->whereNotIn('produccion.estado', self::PRODUCCION_CERRADA)
            ->exists();
    }

    /**
     * Avisa al taller de que editaron una orden que está fabricando.
     *
     * @param array $cambios Mismo formato que orden_ediciones: campo/label/antes/despues.
     */
    public static function ordenEditada(Orden $orden, Usuario $autor, array $cambios): void
    {
        if (empty($cambios) || ! self::tieneProduccionViva($orden)) {
            return;
        }

        $orden->loadMissing('cliente');
        $cliente = $orden->cliente?->nombre ?? 'cliente';

        $delTaller = array_values(array_filter($cambios, [self::class, 'afectaAlTaller']));

        // Un cambio de medidas o de tela mientras el mueble se está armando es
        // urgente de verdad: cada hora que pase se trabaja sobre lo que ya no es.
        $urgente = ! empty($delTaller);

        $detalle = $urgente
            ? collect($delTaller)->map(fn($c) => $c['label'] ?? $c['campo'])->take(3)->implode(' · ')
            : collect($cambios)->map(fn($c) => $c['label'] ?? $c['campo'])->take(3)->implode(' · ');

        $sobran = ($urgente ? count($delTaller) : count($cambios)) - 3;
        if ($sobran > 0) {
            $detalle .= " y {$sobran} cambio(s) más";
        }

        $titulo = $urgente
            ? "Cambió lo que estás fabricando — Orden {$orden->referencia}"
            : "Editaron una orden que estás fabricando — {$orden->referencia}";

        $mensaje = "Orden {$orden->referencia} de {$cliente}: {$detalle}. Lo editó {$autor->nombre}."
            . ($urgente ? ' Revisa antes de seguir armando.' : '');

        // Los tapiceros son supervisores y ya reciben "Orden editada" por cada
        // edición. Mandarles también esta por un cambio de dirección sería el
        // mismo aviso dos veces; solo se les suma cuando les cambia el trabajo.
        foreach (self::destinatarios($autor->id, incluirTapiceros: $urgente) as $d) {
            NotificacionService::crear(
                tipo:      'orden_editada',
                titulo:    $titulo,
                mensaje:   $mensaje,
                datos:     ['orden_id' => $orden->id],
                usuarioId: $d->id,
                urgente:   $urgente,
            );
        }
    }

    /**
     * Cancelaron una orden que el taller está armando. Es el aviso que más
     * urge: cada hora de más es material y mano de obra que ya nadie paga.
     *
     * Se llama ANTES de cancelar las producciones, cuando todavía se ve que
     * había trabajo vivo.
     */
    public static function ordenCancelada(Orden $orden, Usuario $autor): void
    {
        if (! self::tieneProduccionViva($orden)) {
            return;
        }

        $orden->loadMissing('cliente');
        $cliente = $orden->cliente?->nombre ?? 'cliente';

        $productos = DB::table('produccion')
            ->join('orden_items', 'orden_items.id', '=', 'produccion.orden_item_id')
            ->leftJoin('productos', 'productos.id', '=', 'orden_items.producto_id')
            ->where('orden_items.orden_id', $orden->id)
            ->whereNotIn('produccion.estado', self::PRODUCCION_CERRADA)
            ->select('productos.nombre as nombre_producto', 'orden_items.nombre_custom')
            ->get()
            ->map(fn($f) => $f->nombre_producto ?: $f->nombre_custom)
            ->filter()
            ->implode(', ');

        foreach (self::destinatarios($autor->id) as $d) {
            NotificacionService::crear(
                tipo:      'cancelado',
                titulo:    "Cancelaron algo que estás fabricando — Orden {$orden->referencia}",
                mensaje:   "Orden {$orden->referencia} de {$cliente} fue cancelada por {$autor->nombre}."
                         . ($productos ? " Deja de trabajar en: {$productos}." : ' Deja de trabajar en ella.'),
                datos:     ['orden_id' => $orden->id],
                usuarioId: $d->id,
                urgente:   true,
            );
        }
    }

    /**
     * Quién arma los muebles: los encargados de algún paso del taller.
     *
     * Antes se preguntaba por el rol —el ebanista, y los supervisores con la
     * bandera de tapicero—, lo que ataba el aviso a cómo se llamara el cargo.
     * Ahora le llega a quien de verdad tiene pasos a su cargo, sea vendedor,
     * supervisor o lo que sea.
     *
     * Los supervisores ya reciben el aviso genérico de "Orden editada", así que
     * este solo se les suma cuando el cambio es del taller: si no, verían lo
     * mismo dos veces.
     */
    private static function destinatarios(?int $excluirUsuarioId = null, bool $incluirTapiceros = true): \Illuminate\Support\Collection
    {
        return Usuario::where('activo', true)
            ->whereHas('procesosAsignados', fn ($p) => $p->where('activo', true))
            ->when(! $incluirTapiceros, fn($q) => $q->where('rol', '!=', 'supervisor'))
            ->when($excluirUsuarioId, fn($q) => $q->where('id', '!=', $excluirUsuarioId))
            ->get();
    }
}
