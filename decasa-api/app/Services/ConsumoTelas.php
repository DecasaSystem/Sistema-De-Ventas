<?php

namespace App\Services;

use App\Models\CatalogoTela;
use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Produccion;
use App\Models\ProductoConsumoTela;
use App\Models\TelaReserva;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El inventario de telas se mueve solo con las ventas.
 *
 * A cada producto tapizado se le dice cuántos metros lleva (por unidad, y si
 * hace falta por medida). Cuando una orden manda uno al taller con una tela
 * del catálogo, esos metros quedan APARTADOS de la tela (`metros_reservados`);
 * cuando el taller termina la pieza se DESCUENTAN (`metros_disponibles`); y si
 * la orden o la pieza se cancela, se SUELTAN.
 *
 * Cada movimiento queda en `tela_reservas` atado al ítem de la orden (o a la
 * producción, cuando se fabrica para la Reserva sin orden). Por eso
 * soltar y descontar es exacto: se mueve lo que se apartó, no lo que diga hoy
 * la configuración del producto.
 *
 * Todo cuelga de un interruptor (`telas_consumo_activo`). Apagado, aquí no se
 * toca nada —ni se aparta ni se descuenta— y apagarlo suelta lo que estuviera
 * apartado, para que no quede tela bloqueada por una función que ya no corre.
 *
 * Qué ítems apartan tela: los que van al taller con producto de catálogo
 * (para fabricar, personalizado, cambio de tela), siempre que el producto
 * tenga consumo cargado y la tela elegida (`specs.tela`, "Marca · Tipo ·
 * Color") exista en el catálogo. Un diseño especial sin producto o una tela
 * escrita a mano no tienen con qué calcularse, y pasan de largo.
 */
class ConsumoTelas
{
    public const CLAVE = 'telas_consumo_activo';

    /** Estados de producción en los que la tela ya se gastó. */
    private const PRODUCCION_TERMINADA = ['listo', 'entregado'];

    /** Estados de orden en los que no hay nada que apartar. */
    private const ORDEN_SIN_RESERVA = ['cotizacion', 'borrador', 'cancelado'];

    private static ?bool $cacheActivo = null;

    // ── Interruptor ──────────────────────────────────────────────────────────

    public static function activo(): bool
    {
        if (self::$cacheActivo !== null) return self::$cacheActivo;

        // Las pruebas montan el esquema a mano y casi ninguna tiene esta
        // tabla: sin ella la función está apagada, que es lo mismo que en
        // producción hasta que alguien la encienda desde Telas.
        if (! Schema::hasTable('configuracion')) {
            return self::$cacheActivo = false;
        }

        return self::$cacheActivo = DB::table('configuracion')
            ->where('clave', self::CLAVE)
            ->value('valor') === '1';
    }

    /**
     * Enciende o apaga la función. Apagarla suelta todo lo apartado y devuelve
     * cuántas reservas se soltaron, para poder decirlo en pantalla.
     */
    public static function definir(bool $activo): int
    {
        return DB::transaction(function () use ($activo) {
            DB::table('configuracion')->updateOrInsert(
                ['clave' => self::CLAVE],
                ['valor' => $activo ? '1' : '0'],
            );
            self::$cacheActivo = $activo;

            if ($activo) return 0;

            $soltadas = 0;
            foreach (TelaReserva::vivas()->get() as $reserva) {
                self::liberar($reserva);
                $soltadas++;
            }
            return $soltadas;
        });
    }

    public static function olvidarCache(): void
    {
        self::$cacheActivo = null;
    }

    // ── Cuánto lleva cada cosa ───────────────────────────────────────────────

    /**
     * Metros por unidad de un producto, en esa medida si la tiene cargada;
     * si no, el consumo base. Null si al producto no se le ha dicho nada.
     */
    public static function consumoDe(int $productoId, ?int $configId = null): ?float
    {
        if ($configId) {
            $porMedida = ProductoConsumoTela::where('producto_id', $productoId)
                ->where('config_id', $configId)
                ->value('metros');
            if ($porMedida !== null && (float) $porMedida > 0) {
                return (float) $porMedida;
            }
        }

        $base = ProductoConsumoTela::where('producto_id', $productoId)
            ->whereNull('config_id')
            ->value('metros');

        return $base !== null && (float) $base > 0 ? (float) $base : null;
    }

    /**
     * La tela del catálogo que dice un texto "Marca · Tipo · Color" —que es
     * como la pantalla de venta guarda la elegida en `specs.tela`—. Una tela
     * escrita a mano, o que no esté en el catálogo, devuelve null.
     */
    public static function telaDeTexto(?string $texto): ?CatalogoTela
    {
        $partes = array_map('trim', explode(' · ', (string) $texto));
        if (count($partes) !== 3 || in_array('', $partes, true)) {
            return null;
        }
        [$marca, $tipo, $color] = $partes;

        return CatalogoTela::where('marca', $marca)
            ->where('tipo', $tipo)
            ->where('color', $color)
            ->where('activo', true)
            ->first();
    }

    /**
     * Qué tela y cuántos metros necesita un ítem, o null si no hay con qué
     * calcularlo (sin producto, sin consumo cargado, sin tela del catálogo).
     *
     * @return array{tela: CatalogoTela, metros: float, detalle: string}|null
     */
    public static function necesidadDe(OrdenItem $item): ?array
    {
        if (! $item->producto_id || ! $item->vaAlTaller()) return null;

        $porUnidad = self::consumoDe((int) $item->producto_id, $item->combo_config_id ? (int) $item->combo_config_id : null);
        if ($porUnidad === null) return null;

        $tela = self::telaDeTexto(($item->specs_personalizacion ?? [])['tela'] ?? null);
        if (! $tela) return null;

        $nombre  = $item->producto?->nombre ?? $item->nombre_custom ?? "Producto #{$item->producto_id}";
        $detalle = "{$nombre} ×{$item->cantidad}";
        if ($item->variante_detalle) {
            $detalle .= " · {$item->variante_detalle}";
        }

        return [
            'tela'    => $tela,
            'metros'  => round($porUnidad * (int) $item->cantidad, 2),
            'detalle' => mb_substr($detalle, 0, 200),
        ];
    }

    // ── Apartar, descontar, soltar ───────────────────────────────────────────

    /**
     * Deja las reservas de la orden como tienen que estar según sus ítems de
     * hoy: aparta lo que falte, suelta lo que sobre, ajusta lo que cambió de
     * tela o de cantidad. Se llama al confirmar la orden y al editarla.
     *
     * Con $estricto una tela sin metros suficientes tumba la petición (422);
     * sin él se aparta igual y los metros libres quedan en negativo, que es
     * la forma de que en Telas se vea que falta comprar.
     */
    public static function sincronizarOrden(Orden $orden, bool $estricto = true): void
    {
        if (! self::activo()) return;

        $orden->loadMissing('items.produccion', 'items.producto');
        foreach ($orden->items as $item) {
            self::sincronizarItem($item, $orden, $estricto);
        }
    }

    public static function sincronizarItem(OrdenItem $item, Orden $orden, bool $estricto = true): void
    {
        if (! self::activo()) return;

        $viva = TelaReserva::vivas()->where('orden_item_id', $item->id)->first();

        $produccion = $item->relationLoaded('produccion') ? $item->produccion : $item->produccion()->first();

        // Pieza terminada con tela apartada: se gastó, no se suelta. Es el
        // caso raro de una producción cerrada sin pasar por el modelo.
        if ($produccion && in_array($produccion->estado, self::PRODUCCION_TERMINADA, true)) {
            if ($viva) self::consumir($viva);
            return;
        }

        $quiere = self::debeApartar($item, $orden, $produccion) ? self::necesidadDe($item) : null;

        if (! $quiere) {
            if ($viva) self::liberar($viva);
            return;
        }

        $igual = $viva
            && (int) $viva->catalogo_tela_id === (int) $quiere['tela']->id
            && abs((float) $viva->metros - $quiere['metros']) < 0.005;
        if ($igual) return;

        if ($viva) self::liberar($viva);
        self::reservar(['orden_item_id' => $item->id], $quiere, $estricto);
    }

    // ── Producir para la Reserva (sin orden) ─────────────────────────────────

    /**
     * Qué tela y cuántos metros necesita una producción para la Reserva.
     *
     * La tela sale de la variante con la que se produce (marca / tipo /
     * color, que es como se guarda en `producto_variantes` y en el catálogo
     * de telas) o, si no trae variante de tela, de `specs.tela`.
     *
     * @return array{tela: CatalogoTela, metros: float, detalle: string}|null
     */
    public static function necesidadDeProduccion(Produccion $p): ?array
    {
        if (! $p->producto_id) return null;

        $porUnidad = self::consumoDe((int) $p->producto_id, $p->combo_config_id ? (int) $p->combo_config_id : null);
        if ($porUnidad === null) return null;

        $tela = null;
        $v    = $p->variante_id ? ($p->relationLoaded('variante') ? $p->variante : $p->variante()->first()) : null;
        if ($v && $v->marca && $v->marca_tela && $v->nombre_color) {
            $tela = CatalogoTela::where('marca', $v->marca)
                ->where('tipo', $v->marca_tela)
                ->where('color', $v->nombre_color)
                ->where('activo', true)
                ->first();
        }
        $tela ??= self::telaDeTexto(($p->specs ?? [])['tela'] ?? null);
        if (! $tela) return null;

        $nombre  = $p->producto?->nombre ?? "Producto #{$p->producto_id}";
        $detalle = "{$nombre} ×{$p->cantidad}";
        if ($p->variante_detalle) $detalle .= " · {$p->variante_detalle}";
        $detalle .= ' (Reserva)';

        return [
            'tela'    => $tela,
            'metros'  => round($porUnidad * (int) $p->cantidad, 2),
            'detalle' => mb_substr($detalle, 0, 200),
        ];
    }

    /**
     * Aparta la tela de una producción para la Reserva. Se llama al crearla
     * desde "Producir"; con $estricto, sin metros suficientes tumba la
     * petición (422), igual que al crear una orden.
     */
    public static function reservarProduccion(Produccion $p, bool $estricto = true): void
    {
        if (! self::activo() || ! $p->esReserva()) return;
        if (in_array($p->estado, [...self::PRODUCCION_TERMINADA, 'cancelado', 'en_reserva'], true)) return;

        $quiere = self::necesidadDeProduccion($p);
        if (! $quiere) return;

        $viva = TelaReserva::vivas()->where('produccion_id', $p->id)->first();
        if ($viva) {
            $igual = (int) $viva->catalogo_tela_id === (int) $quiere['tela']->id
                && abs((float) $viva->metros - $quiere['metros']) < 0.005;
            if ($igual) return;
            self::liberar($viva);
        }

        self::reservar(['produccion_id' => $p->id], $quiere, $estricto);
    }

    /** Cuando la pieza para la Reserva queda lista: lo apartado se descuenta. */
    public static function consumirProduccion(int $produccionId): void
    {
        if (! self::activo()) return;

        $viva = TelaReserva::vivas()->where('produccion_id', $produccionId)->first();
        if ($viva) self::consumir($viva);
    }

    /** Cuando la pieza para la Reserva se cancela: lo apartado vuelve a estar libre. */
    public static function liberarProduccion(int $produccionId): void
    {
        if (! self::activo()) return;

        $viva = TelaReserva::vivas()->where('produccion_id', $produccionId)->first();
        if ($viva) self::liberar($viva);
    }

    // ── Piezas de una orden ──────────────────────────────────────────────────

    /** Cuando la pieza de un ítem queda lista: lo apartado se descuenta. */
    public static function consumirItem(int $ordenItemId): void
    {
        if (! self::activo()) return;

        $viva = TelaReserva::vivas()->where('orden_item_id', $ordenItemId)->first();
        if ($viva) self::consumir($viva);
    }

    /** Cuando el ítem o su pieza se cancela: lo apartado vuelve a estar libre. */
    public static function liberarItem(int $ordenItemId): void
    {
        if (! self::activo()) return;

        $viva = TelaReserva::vivas()->where('orden_item_id', $ordenItemId)->first();
        if ($viva) self::liberar($viva);
    }

    public static function liberarOrden(Orden $orden): void
    {
        if (! self::activo()) return;

        $ids = $orden->items()->pluck('id');
        foreach (TelaReserva::vivas()->whereIn('orden_item_id', $ids)->get() as $viva) {
            self::liberar($viva);
        }
    }

    // ── Privados ─────────────────────────────────────────────────────────────

    private static function debeApartar(OrdenItem $item, Orden $orden, $produccion): bool
    {
        if (in_array($orden->estado, self::ORDEN_SIN_RESERVA, true)) return false;
        if (! $item->estaVivo() || ! $item->vaAlTaller())            return false;
        if ($produccion && $produccion->estado === 'cancelado')        return false;

        return true;
    }

    /** @param array $duenio  ['orden_item_id' => id] o ['produccion_id' => id]: de qué cuelga la reserva. */
    private static function reservar(array $duenio, array $quiere, bool $estricto): void
    {
        $tela   = CatalogoTela::lockForUpdate()->find($quiere['tela']->id);
        $metros = $quiere['metros'];
        $libres = round((float) $tela->metros_disponibles - (float) $tela->metros_reservados, 2);

        if ($estricto && $metros > $libres + 0.005) {
            $nombreTela = "{$tela->marca} · {$tela->tipo} · {$tela->color}";
            throw new HttpResponseException(response()->json([
                'message' => "«{$quiere['detalle']}» necesita {$metros} m de {$nombreTela} y solo hay {$libres} m libres. "
                           . 'Elige otra tela o recarga el inventario de telas.',
            ], 422));
        }

        $tela->increment('metros_reservados', $metros);

        TelaReserva::create($duenio + [
            'catalogo_tela_id' => $tela->id,
            'metros'           => $metros,
            'estado'           => TelaReserva::RESERVADA,
            'detalle'          => $quiere['detalle'],
        ]);
    }

    private static function consumir(TelaReserva $reserva): void
    {
        $metros = (float) $reserva->metros;
        CatalogoTela::where('id', $reserva->catalogo_tela_id)->decrement('metros_reservados', $metros);
        CatalogoTela::where('id', $reserva->catalogo_tela_id)->decrement('metros_disponibles', $metros);
        $reserva->update(['estado' => TelaReserva::CONSUMIDA]);
    }

    private static function liberar(TelaReserva $reserva): void
    {
        CatalogoTela::where('id', $reserva->catalogo_tela_id)->decrement('metros_reservados', (float) $reserva->metros);
        $reserva->update(['estado' => TelaReserva::LIBERADA]);
    }
}
