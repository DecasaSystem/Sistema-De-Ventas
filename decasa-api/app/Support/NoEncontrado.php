<?php

namespace App\Support;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que se le dice a la gente cuando pide algo que no existe.
 *
 * Laravel contesta "No query results for model [App\Models\Orden] 939", y la
 * pantalla lo mostraba tal cual. Casi siempre es algo que se borró —una orden
 * eliminada a la que todavía apunta un aviso o un enlace viejo— y lo útil es
 * decirlo en palabras: qué era, y si se eliminó, cuándo, quién y por qué.
 */
class NoEncontrado
{
    /** Cómo se llama cada cosa, para la frase. */
    private const NOMBRES = [
        'Orden'               => 'Esa orden',
        'OrdenItem'           => 'Ese producto de la orden',
        'DespachoItem'        => 'Esa entrega',
        'Despacho'            => 'Esa ruta',
        'Comision'            => 'Esa comisión',
        'Pago'                => 'Ese pago',
        'Cliente'             => 'Ese cliente',
        'Producto'            => 'Ese producto',
        'Usuario'             => 'Esa persona',
        'Tienda'              => 'Esa tienda',
        'Produccion'          => 'Esa pieza del taller',
        'NominaPago'          => 'Ese pago de nómina',
        'NominaBonificacion'  => 'Ese bono',
        'TiendaReemplazo'     => 'Ese reemplazo',
        'Devolucion'          => 'Esa devolución',
        'Notificacion'        => 'Ese aviso',
    ];

    /** @return array{message: string, no_existe: true, recurso: string, id: mixed, eliminada?: bool} */
    public static function respuesta(ModelNotFoundException $e): array
    {
        $clase = class_basename($e->getModel());
        $ids   = $e->getIds();
        $id    = is_array($ids) ? ($ids[0] ?? null) : $ids;

        if ($clase === 'Orden' && $id && ($borrada = self::ordenEliminada((int) $id))) {
            return $borrada;
        }

        $quien = self::NOMBRES[$clase] ?? 'Eso';

        return [
            'message'   => "{$quien} ya no existe: pudo haberse eliminado. Vuelve a la lista y búscala otra vez.",
            'no_existe' => true,
            'recurso'   => $clase,
            'id'        => $id,
        ];
    }

    /** Si la orden se eliminó, lo que quedó escrito al eliminarla. */
    private static function ordenEliminada(int $ordenId): ?array
    {
        try {
            if (! Schema::hasTable('ordenes_eliminadas')) return null;

            $e = DB::table('ordenes_eliminadas as e')
                ->leftJoin('usuarios as u', 'u.id', '=', 'e.eliminada_por_id')
                ->where('e.orden_id', $ordenId)
                ->orderByDesc('e.id')
                ->first(['e.referencia', 'e.cliente_nombre', 'e.motivo', 'e.created_at', 'u.nombre as quien']);
        } catch (\Throwable) {
            return null;
        }

        if (! $e) return null;

        $cuando = \Carbon\Carbon::parse($e->created_at)
            ->setTimezone(\App\Http\Controllers\StatsController::TZ_NEGOCIO)->format('d/m/Y');
        $orden  = $e->referencia ? "La orden {$e->referencia}" : 'Esta orden';
        $de     = $e->cliente_nombre ? " (de {$e->cliente_nombre})" : '';
        $porQue = $e->motivo ? " Motivo: {$e->motivo}" : '';

        return [
            'message'   => "{$orden}{$de} se eliminó el {$cuando}" . ($e->quien ? " por {$e->quien}" : '') . ".{$porQue}",
            'no_existe' => true,
            'eliminada' => true,
            'recurso'   => 'Orden',
            'id'        => $ordenId,
        ];
    }
}
