<?php

namespace App\Services;

use App\Models\DespachoItem;
use App\Models\Orden;
use App\Models\Produccion;
use App\Models\ProduccionPaso;
use App\Models\ProduccionRetorno;
use App\Models\Usuario;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Devolver al taller algo que ya había salido de él.
 *
 * El caso: la pieza está en la bodega esperando el camión —o ya subida a una
 * ruta que no ha arrancado— y aparece el problema. Le falta una manija, la
 * tela no era esa, se rayó al moverla. Devolverla tiene que costar lo que
 * cuesta arreglarla, no lo que costó fabricarla.
 *
 * Por eso quien la devuelve decide DOS cosas:
 *
 *   1. A qué paso vuelve ($destino): dónde se retoma el trabajo. Ese paso
 *      queda `en_proceso` y le aparece al encargado en "Mis pasos".
 *   2. Qué pasos se rehacen ($idsRehacer): de ahí en adelante, cuáles hay que
 *      volver a hacer. Los que NO se marquen se quedan `completado`, y como el
 *      flujo siempre busca "el siguiente paso pendiente" (ver
 *      `ProduccionController::completarPaso`), los salta solo.
 *
 * Ejemplo real: un comedor lacado al que se le partió una pata. Vuelve a
 * ebanistería, se rehacen ebanistería y despacho, y NO se rehacen tapizado ni
 * laca. Al cerrar ebanistería, el flujo salta directo a despacho.
 *
 * Despacho se rehace siempre: la pieza está adentro otra vez y tiene que
 * volver a salir por la misma puerta por la que salió.
 *
 * Lo que vuelve por aquí NO es una `Devolucion`: esa es para lo que ya se le
 * entregó al cliente y volvió en el camión, y arrastra plata, inventario y
 * decisión del supervisor. Aquí el mueble nunca salió de la casa.
 */
class RetornoAlTaller
{
    /** Estados de los que se puede devolver: salió del taller y no se ha entregado. */
    public const DEVOLVIBLES = ['pendiente_despachador', 'listo'];

    /**
     * ¿Por qué NO se puede devolver esta pieza? Null si sí se puede.
     *
     * Devuelve el motivo en vez de un booleano para que la pantalla pueda
     * decirlo con palabras en lugar de esconder el botón sin explicar nada.
     */
    public static function porQueNoSePuede(Produccion $produccion): ?string
    {
        if ($produccion->estado === 'entregado') {
            return 'Esta pieza ya se entregó. Lo que vuelve después de entregada se registra como devolución.';
        }
        if ($produccion->estado === 'en_reserva') {
            return 'Esta pieza entró a la Reserva de Fábrica; ya no está en despacho.';
        }
        if ($produccion->estado === 'cancelado') {
            return 'Esta pieza está cancelada.';
        }
        if (! in_array($produccion->estado, self::DEVOLVIBLES, true)) {
            return 'Esta pieza todavía está en el taller: para corregir un paso, devuélvelo desde "Mis pasos".';
        }
        if (self::vaEnCamino($produccion)) {
            return 'El camión ya salió con esta pieza. Si vuelve, se registra como devolución.';
        }
        if (self::pasosQueSePuedenRehacer($produccion)->isEmpty()) {
            return 'Esta pieza no tiene pasos completados a los que volver.';
        }

        return null;
    }

    /** ¿Va en una ruta que ya arrancó? Entonces no está en la bodega: está en la calle. */
    public static function vaEnCamino(Produccion $produccion): bool
    {
        $item = $produccion->ordenItem;
        if (! $item) return false;

        return DespachoItem::where('orden_id', $item->orden_id)
            ->where('estado', 'pendiente')
            ->whereHas('despacho', fn ($q) => $q->where('estado', 'en_ruta'))
            ->exists();
    }

    /**
     * Los pasos a los que se puede volver: los que se hicieron.
     *
     * Un paso cancelado o pendiente no es trabajo que se pueda rehacer. El de
     * despacho sí se ofrece como destino, porque a veces el arreglo es de
     * despacho mismo: embalar de nuevo, cambiar el empaque, completar lo que
     * faltaba en la caja.
     *
     * @return Collection<int, ProduccionPaso>
     */
    public static function pasosQueSePuedenRehacer(Produccion $produccion): Collection
    {
        return ProduccionPaso::where('produccion_id', $produccion->id)
            ->where('estado', 'completado')
            ->orderBy('orden')
            ->get();
    }

    /**
     * Devuelve la pieza al taller.
     *
     * @param  array<int>  $idsRehacer  pasos que se vuelven a hacer; el destino
     *                                  y el de despacho entran siempre.
     */
    public static function regresar(
        Produccion $produccion,
        ProduccionPaso $destino,
        array $idsRehacer,
        string $motivo,
        ?string $fotoUrl,
        Usuario $quien,
    ): ProduccionRetorno {
        return DB::transaction(function () use ($produccion, $destino, $idsRehacer, $motivo, $fotoUrl, $quien) {
            $rehacer = self::pasosARehacer($produccion, $destino, $idsRehacer);

            // Primero bajarla de la ruta: mientras la pieza siga contando como
            // entregable se puede calcular qué llevaba la entrega. Después de
            // moverle el estado a la producción, ya no.
            $entregaId = self::bajarDeLaRuta($produccion);

            // El paso con el problema anota el rechazo igual que cuando lo
            // devuelve el compañero del paso siguiente: es el mismo hecho, y
            // así se lee en el mismo sitio de la pantalla del taller.
            $destino->update([
                'estado'           => 'en_proceso',
                'iniciado_at'      => now(),
                'completado_por'   => null,
                'completado_at'    => null,
                'trabajadores'     => null,
                'rechazos'         => (int) $destino->rechazos + 1,
                'ultimo_rechazo'   => $motivo,
                'rechazado_por_id' => $quien->id,
                'rechazado_at'     => now(),
            ]);

            // Los demás quedan esperando turno. Se limpia quién los hizo porque
            // se van a hacer de nuevo y puede que con otra gente; las horas ya
            // trabajadas (`paso_trabajadores`) se conservan: ese trabajo se
            // hizo y se paga.
            $otros = $rehacer->pluck('id')->reject(fn ($id) => $id === $destino->id)->all();
            if ($otros) {
                ProduccionPaso::whereIn('id', $otros)->update([
                    'estado'         => 'pendiente',
                    'iniciado_at'    => null,
                    'completado_por' => null,
                    'completado_at'  => null,
                    'trabajadores'   => null,
                ]);
            }

            // Volver a despacho es volver a la puerta de salida, no al taller:
            // la pieza no la toca nadie más, solo se revisa y sale.
            $produccion->update([
                'estado'         => $destino->tipo_proceso === ProduccionPaso::DESPACHO
                    ? 'pendiente_despachador'
                    : 'en_proceso',
                'fecha_real'     => null,
                'despachado_por' => null,
                'motivo_retraso' => 'Devuelto de despacho: ' . $motivo,
            ]);

            self::ponerLaOrdenAlDia($produccion);

            return ProduccionRetorno::create([
                'produccion_id'    => $produccion->id,
                'paso_destino_id'  => $destino->id,
                'pasos_rehacer'    => $rehacer->pluck('id')->all(),
                'motivo'           => $motivo,
                'foto_url'         => $fotoUrl,
                'despacho_item_id' => $entregaId,
                'devuelto_por_id'  => $quien->id,
            ]);
        });
    }

    /**
     * Qué pasos se rehacen de verdad.
     *
     * De lo que se marcó se toma solo lo que tiene sentido: pasos completados
     * de esta misma pieza, del destino en adelante. Y se añade lo obligatorio
     * —el destino y el despacho— aunque no venga marcado.
     *
     * @param  array<int>  $idsRehacer
     * @return Collection<int, ProduccionPaso>
     */
    private static function pasosARehacer(Produccion $produccion, ProduccionPaso $destino, array $idsRehacer): Collection
    {
        $marcados = array_map('intval', $idsRehacer);

        return self::pasosQueSePuedenRehacer($produccion)
            ->where('orden', '>=', $destino->orden)
            ->filter(fn (ProduccionPaso $p) =>
                $p->id === $destino->id
                || $p->tipo_proceso === ProduccionPaso::DESPACHO
                || in_array($p->id, $marcados, true))
            ->values();
    }

    /**
     * La baja de la ruta en la que estuviera subida.
     *
     * Dejarla ahí sería mandar al conductor a cargar algo que está otra vez en
     * el taller. Solo entran rutas que no han salido (`borrador`/`asignado`);
     * una `en_ruta` ni llega aquí, la frena `porQueNoSePuede()`.
     *
     * Si la entrega se queda sin nada que llevar, se elimina y la orden vuelve
     * a la cola de despacho por su cuenta.
     *
     * @return int|null  el id de la entrega de la que se bajó, para el rastro.
     */
    private static function bajarDeLaRuta(Produccion $produccion): ?int
    {
        $item = $produccion->ordenItem;
        if (! $item) return null;

        $entrega = DespachoItem::where('orden_id', $item->orden_id)
            ->where('estado', 'pendiente')
            ->whereHas('despacho', fn ($q) => $q->whereIn('estado', ['borrador', 'asignado']))
            ->first();

        if (! $entrega) return null;

        // Una entrega sin líneas escritas lleva "todo lo que falte", así que no
        // hay renglón que quitar: se escriben ahora para poder sacar esta pieza
        // y dejar lo demás como estaba.
        if ($entrega->lineas()->doesntExist()) {
            $orden = Orden::with('items.produccion', 'items.producto:id,nombre')->find($item->orden_id);
            if ($orden) {
                EntregaService::fijarLineas($entrega, EntregaService::entregablesDe($orden));
            }
        }

        $entrega->lineas()->where('orden_item_id', $item->id)->delete();

        if ($entrega->lineas()->doesntExist()) {
            $despacho = $entrega->despacho;
            $entrega->delete();
            $despacho?->items()->orderBy('posicion')->get()
                ->each(fn ($it, $i) => $it->update(['posicion' => $i + 1]));
        }

        return $entrega->id;
    }

    /**
     * La orden deja de estar lista para entrega: le falta esta pieza otra vez.
     *
     * Se recalcula con la misma cuenta que usa la entrega (`estadoTrasEntrega`)
     * para no inventar una regla paralela: si queda algo en el taller vuelve a
     * `en_produccion`, y si lo demás ya estaba listo se queda en
     * `listo_entrega` — que es lo correcto cuando la orden lleva varias piezas
     * y solo una se devolvió.
     */
    private static function ponerLaOrdenAlDia(Produccion $produccion): void
    {
        $orden = $produccion->ordenItem?->orden;
        if (! $orden) return;

        // `estadoTrasEntrega` solo entiende órdenes en marcha; una cancelada,
        // una que ya se entregó entera o un borrador no se tocan.
        if (in_array($orden->estado, ['cancelado', 'entregado', 'borrador', 'cotizacion'], true)) return;

        $orden->refresh()->load('items.produccion');
        $nuevo = $orden->estadoTrasEntrega();

        if ($nuevo !== $orden->estado) {
            $cambios = ['estado' => $nuevo];
            if ($nuevo === 'en_produccion') $cambios['listo_entrega_at'] = null;
            $orden->update($cambios);
        }
    }
}
