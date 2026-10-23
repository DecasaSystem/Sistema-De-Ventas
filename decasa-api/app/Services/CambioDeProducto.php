<?php

namespace App\Services;

use App\Http\Controllers\ComisionController;
use App\Models\Devolucion;
use App\Models\Inventario;
use App\Models\InventarioMovimiento;
use App\Models\InventarioVariante;
use App\Models\InventarioVarianteCombinacion;
use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\OrdenMensaje;
use App\Models\Produccion;
use App\Models\Producto;
use App\Models\ProductoVariante;
use App\Models\ProductoVarianteConfig;
use App\Models\Tienda;
use App\Models\Usuario;
use App\Support\DeclaracionEspeciales;
use App\Support\Esquema;
use App\Support\StockMedida;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * El cliente ya recibió el mueble y después dice que no le sirvió.
 *
 * No es una garantía —no se dañó— ni lo que devuelve el conductor en la
 * puerta: la entrega sí ocurrió. Es el respaldo para cuando, días después, la
 * señora llama y dice que el sofá no le cupo o que el color no le gustó, y lo
 * cambia por otra cosa.
 *
 * En un solo paso:
 *  1. Lo devuelto deja de cobrarse (entero queda como rastro, `devuelto_en`;
 *     si era parte, esas unidades salen del renglón) y vuelve al inventario si
 *     está para vender, o sale como merma.
 *  2. El reemplazo entra como renglón nuevo, por el mismo camino que una venta:
 *     de catálogo apartado en la tienda que lo tiene, de catálogo para
 *     fabricar, o un diseño especial. Cualquiera de los dos últimos entra a
 *     producción.
 *  3. El total se recalcula. Lo ya pagado no se toca: si lo nuevo vale más,
 *     el saldo sube y el cliente paga la diferencia; si vale menos, le queda a
 *     favor. La comisión sigue al valor, como en cualquier cambio.
 *
 * Si todavía no se sabe por qué lo cambia ("lo voy a pensar", hay que
 * cotizarlo), se puede devolver sin reemplazo: la orden queda abierta
 * (`pendiente_anticipo`) y el producto se agrega luego con Editar. Es lo que
 * hacía antes `cambiarProducto` y se conserva igual.
 *
 * Lo decide un supervisor: mueve el valor de la venta.
 */
class CambioDeProducto
{
    /** Estados donde ya hubo una entrega y no hay un camión andando. */
    private const ESTADOS_CON_REEMPLAZO = ['entregado', 'listo_entrega', 'en_produccion', 'pendiente_anticipo'];

    /**
     * @param array<string,mixed> $data  ya validado por el controlador
     * @return array<string,mixed>       lo que responde la ruta
     */
    public static function ejecutar(Orden $orden, array $data, Usuario $quien): array
    {
        $reemplazo = $data['reemplazo'] ?? null;
        $tipo      = $reemplazo['tipo'] ?? null;

        // Sin reemplazo, la orden queda abierta para editarla: eso solo tiene
        // sentido si ya estaba entregada (si no, sacarla de "en producción" o
        // "lista" la pondría en un estado que no es el suyo).
        if (! $tipo && $orden->estado !== 'entregado') {
            self::fallar('Esta orden todavía no está entregada: escoge desde ya por qué lo cambia.');
        }
        if ($tipo && ! in_array($orden->estado, self::ESTADOS_CON_REEMPLAZO, true)) {
            self::fallar('No se puede cambiar un producto en una orden que está en "' . $orden->estado . '".');
        }
        if ($orden->estado !== 'entregado' && $orden->tieneDespachoActivo()) {
            self::fallar('La orden va en una entrega: espera a que termine para registrar el cambio.');
        }

        $item = $orden->items->firstWhere('id', (int) $data['orden_item_id']);
        if (! $item) {
            self::fallar('Ese producto no es de esta orden.');
        }
        if (! $item->estaVivo()) {
            self::fallar('Ese producto ya se había devuelto.');
        }

        // Solo se devuelve lo que el cliente tiene en la casa: lo entregado y
        // que no esté ya en una garantía sin resolver.
        $enCasa = self::unidadesEnCasa($item, $orden);
        $n      = (int) ($data['cantidad'] ?? $enCasa);
        if ($enCasa < 1) {
            self::fallar('De ese producto no se le ha entregado nada al cliente (o está en una garantía): no hay qué devolver.');
        }
        if ($n < 1 || $n > $enCasa) {
            self::fallar("Se pueden devolver hasta {$enCasa} de ese producto: es lo que tiene el cliente.");
        }

        $fecha       = $data['fecha'] ?? now('America/Bogota')->toDateString();
        $plan        = self::planDevuelto($data, $item, $orden, (bool) $tipo);
        $valorViejo  = round($n * (float) $item->precio_unitario, 2);
        $nombreViejo = self::nombre($item);

        $resultado = DB::transaction(function () use ($orden, $item, $data, $n, $fecha, $plan, $tipo, $reemplazo, $quien, $nombreViejo) {
            $orden = Orden::lockForUpdate()->findOrFail($orden->id);

            // En una orden entregada todo está en la casa. Si la caché dice
            // otra cosa (órdenes viejas), se pone al día antes de agregar el
            // reemplazo: si no, al quedar la orden abierta esos productos
            // saldrían otra vez como "por entregar".
            if ($orden->estado === 'entregado' && Esquema::columna('orden_items', 'cantidad_entregada')) {
                OrdenItem::where('orden_id', $orden->id)->whereNull('devuelto_en')
                    ->whereColumn('cantidad_entregada', '<', 'cantidad')
                    ->update(['cantidad_entregada' => DB::raw('cantidad')]);
            }

            $item = OrdenItem::lockForUpdate()->findOrFail($item->id);

            // 1. Lo devuelto. Si ya está aquí, entra de una a la tienda (o a
            //    la fábrica) que se escogió; si se recoge después, entra cuando
            //    llegue (`recibir`). La orden cambia igual ahora.
            if ($plan['recogida'] === 'ya' && $plan['destino'] === 'tienda' && self::entraAlInventario($item)) {
                self::volverAlInventario($item, $orden, (int) $plan['tienda_id'], $n, $quien);
            }

            // 2. Deja de cobrarse.
            if ($n >= (int) $item->cantidad) {
                $item->update(['devuelto_en' => $fecha, 'motivo_devolucion' => $data['motivo']]);
            } else {
                // Vuelve solo parte: esas unidades salen del renglón y de lo
                // entregado. Lo demás se queda como está.
                $item->update([
                    'cantidad'           => (int) $item->cantidad - $n,
                    'cantidad_entregada' => max(0, self::entregadas($item, $orden) - $n),
                ]);
            }

            // 3. El reemplazo.
            $nuevo = $tipo ? self::crearReemplazo($orden, $reemplazo, $data['motivo'], $quien) : null;

            $orden->recalcularTotal();

            $devolucion = Devolucion::create([
                'orden_id'         => $orden->id,
                'orden_item_id'    => $item->id,
                'cantidad'         => $n,
                'motivo'           => $data['motivo'],
                'foto_url'         => $data['foto_url'] ?? null,
                'fecha'            => $fecha,
                'reportado_por_id' => $quien->id,
                // Ya está resuelta: se cambia por otro producto. No espera que
                // nadie decida nada, a diferencia de la que llega dañada.
                'estado'           => 'cambio',
                'decidido_por_id'  => $quien->id,
                'decidido_at'      => now(),
                'notas_decision'   => $nuevo
                    ? 'Se cambia por ' . self::nombre($nuevo) . self::comoSale($nuevo, $orden) . '.'
                    : 'El cliente lo cambia por otro producto.',
            ] + (Esquema::columna('devoluciones', 'destino') ? [
                'destino'             => $plan['destino'],
                'tienda_destino_id'   => $plan['destino'] === 'tienda' ? $plan['tienda_id'] : null,
                'recoger_el'          => $plan['recogida'] === 'programada' ? $plan['fecha'] : null,
                'recoger_con_entrega' => $plan['recogida'] === 'con_entrega',
                'recibido_at'         => $plan['recogida'] === 'ya' ? now() : null,
                'recibido_por_id'     => $plan['recogida'] === 'ya' ? $quien->id : null,
            ] : []));

            // 4. La orden queda en lo que le toca con lo que tiene pendiente.
            if ($nuevo) {
                GarantiaService::ponerOrdenAlDia($orden);
            } else {
                $orden->update(['estado' => 'pendiente_anticipo']);
            }

            return compact('nuevo', 'devolucion');
        });

        /** @var OrdenItem|null $nuevo */
        $nuevo = $resultado['nuevo'];
        $orden->refresh()->load(['items', 'pagos']);

        // El valor de la orden cambió: la comisión tiene que seguirlo.
        ComisionController::sincronizarValorOrden($orden);

        $valorNuevo = $nuevo ? round((int) $nuevo->cantidad * (float) $nuevo->precio_unitario, 2) : 0.0;
        $diferencia = $nuevo ? round($valorNuevo - $valorViejo, 2) : null;
        $quedoVacia = $orden->items->filter->estaVivo()->isEmpty();

        self::anotar($orden, $quien, $data, $n, $nombreViejo, $fecha, $plan, self::entraAlInventario($item), $nuevo, $diferencia);

        if ($plan['recogida'] !== 'ya') {
            self::avisarRecogida($resultado['devolucion'], $orden, $nombreViejo);
        }

        if ($nuevo && $nuevo->vaAlTaller()) {
            self::avisarProduccion($orden, $nuevo);
        }

        $saldo = $orden->saldoPendiente();

        return [
            'message' => match (true) {
                ! $nuevo   => $quedoVacia
                    ? 'Orden reabierta y sin productos: agrégale el reemplazo desde Editar para no dejarla vacía.'
                    : 'Orden reabierta. Ya puedes agregarle el producto nuevo desde Editar.',
                $saldo > 0.5  => 'Cambio registrado. El cliente debe $' . number_format($saldo, 0, ',', '.') . '.',
                $saldo < -0.5 => 'Cambio registrado. Le quedan $' . number_format(-$saldo, 0, ',', '.') . ' a favor.',
                default       => 'Cambio registrado. La orden queda al día.',
            },
            'quedo_vacia'     => $quedoVacia,
            'por_recoger'     => $plan['recogida'] !== 'ya',
            'devolucion_id'   => $resultado['devolucion']->id,
            'valor_devuelto'  => $valorViejo,
            'valor_nuevo'     => $valorNuevo,
            'diferencia'      => $diferencia,
            'orden_item_nuevo_id' => $nuevo?->id,
            'estado'          => $orden->estado,
            'valor_total'     => (float) $orden->valor_total,
            'total_pagado'    => $orden->totalPagado(),
            'saldo_pendiente' => $saldo,
        ];
    }

    /**
     * Cuántas tiene el cliente en la casa de ese producto. En una orden
     * `entregado` es todo el renglón —eso significa el estado—, aunque la
     * caché `cantidad_entregada` diga menos (órdenes viejas, marcadas a mano).
     */
    public static function entregadas(OrdenItem $item, Orden $orden): int
    {
        $ce = (int) ($item->cantidad_entregada ?? 0);

        return $orden->estado === 'entregado' ? max($ce, (int) $item->cantidad) : $ce;
    }

    /** Lo que el cliente tiene en la casa de ese producto y puede devolver. */
    public static function unidadesEnCasa(OrdenItem $item, Orden $orden): int
    {
        if (! $item->estaVivo()) return 0;

        // Las que esperan dictamen de garantía no se pueden cambiar a la vez.
        $enGarantia = Esquema::tabla('garantias')
            ? (int) \App\Models\Garantia::where('orden_item_id', $item->id)
                ->whereIn('estado', ['pendiente', 'a_domicilio', 'por_devolver'])->sum('cantidad')
            : 0;

        return max(0, self::entregadas($item, $orden) - $enGarantia);
    }

    // ── Lo devuelto: a dónde va y cuándo llega ───────────────────────────────

    /**
     * ¿Puede volver a la venta? Lo de catálogo sí (también lo que se fabricó
     * igual al catálogo: es una unidad más de ese producto). Un diseño
     * especial, un personalizado o un mueble único no tienen inventario al
     * cual volver: pueden llegar a la tienda o a la fábrica, pero no se ponen
     * a la venta solos.
     */
    public static function entraAlInventario(OrdenItem $item): bool
    {
        if (! $item->producto_id || $item->producto_unico) return false;

        return ! $item->es_personalizado || (bool) $item->fabricar_pedido;
    }

    /**
     * Qué se hace con lo devuelto:
     *  - destino: `tienda` (una tienda o la fábrica/bodega) o `merma`.
     *  - recogida: `ya` (ya está aquí, entra de una), `programada` (se recoge
     *    ese día) o `con_entrega` (se recoge cuando le lleven el nuevo).
     *
     * Sin `devuelto` (pantallas viejas) se hace como antes: vuelve de una a la
     * tienda de donde salió, o merma si se desmarcó.
     *
     * @return array{destino:string, tienda_id:?int, recogida:string, fecha:?string}
     */
    private static function planDevuelto(array $data, OrdenItem $item, Orden $orden, bool $hayReemplazo): array
    {
        $d = $data['devuelto'] ?? null;

        if (! $d) {
            $vuelve = ($data['vuelve_al_stock'] ?? true) && self::entraAlInventario($item);
            $tienda = $vuelve ? ((int) ($data['tienda_devuelto_id'] ?? 0) ?: (int) ($item->tienda_origen_id ?? $orden->tienda_id)) : null;
            if ($tienda && ! Tienda::whereKey($tienda)->exists()) {
                self::fallar('Escoge a qué tienda vuelve el producto.');
            }

            return ['destino' => $vuelve ? 'tienda' : 'merma', 'tienda_id' => $tienda, 'recogida' => 'ya', 'fecha' => null];
        }

        $destino  = $d['destino'];
        $tiendaId = $destino === 'tienda' ? (int) ($d['tienda_id'] ?? 0) : null;
        if ($destino === 'tienda' && (! $tiendaId || ! Tienda::whereKey($tiendaId)->exists())) {
            self::fallar('Escoge a qué tienda o a la fábrica va lo que devuelve.');
        }

        $recogida = $d['recogida'] ?? 'ya';
        $fecha    = null;
        if ($recogida === 'programada') {
            $fecha = $d['fecha_recogida'] ?? null;
            if (! $fecha) self::fallar('Escoge qué día se recoge.');
            if (Carbon::parse($fecha)->lt(now('America/Bogota')->startOfDay())) {
                self::fallar('La recogida no puede quedar para un día que ya pasó.');
            }
            $fecha = Carbon::parse($fecha)->toDateString();
        }
        if ($recogida === 'con_entrega' && ! $hayReemplazo) {
            self::fallar('No hay producto nuevo que llevarle: programa un día para recogerlo.');
        }

        return ['destino' => $destino, 'tienda_id' => $tiendaId, 'recogida' => $recogida, 'fecha' => $fecha];
    }

    /**
     * Llegó lo devuelto: entra al inventario de donde se dijo (o merma).
     * Quien lo recibe puede cambiar el destino: lo programaron para la tienda
     * y llegó rayado, o se decidió mandarlo a la fábrica.
     *
     * @param array{destino?:string, tienda_id?:int|null} $data
     */
    public static function recibir(Devolucion $d, array $data, Usuario $quien): Devolucion
    {
        DB::transaction(function () use (&$d, $data, $quien) {
            $d = Devolucion::lockForUpdate()->findOrFail($d->id);
            if (! $d->porRecoger()) {
                self::fallar('Esa devolución ya se recibió.');
            }

            $destino  = $data['destino'] ?? $d->destino;
            $tiendaId = $destino === 'tienda' ? (int) ($data['tienda_id'] ?? $d->tienda_destino_id) : null;
            if ($destino === 'tienda' && (! $tiendaId || ! Tienda::whereKey($tiendaId)->exists())) {
                self::fallar('Escoge a qué tienda o a la fábrica llegó.');
            }

            $item  = OrdenItem::findOrFail($d->orden_item_id);
            $orden = Orden::findOrFail($d->orden_id);
            if ($destino === 'tienda' && self::entraAlInventario($item)) {
                self::volverAlInventario($item, $orden, $tiendaId, (int) $d->cantidad, $quien);
            }

            $d->update([
                'destino'           => $destino,
                'tienda_destino_id' => $tiendaId,
                'recibido_at'       => now(),
                'recibido_por_id'   => $quien->id,
            ]);

            OrdenMensaje::create([
                'orden_id'   => $orden->id,
                'usuario_id' => $quien->id,
                'mensaje'    => "📦 Llegó lo devuelto: {$d->cantidad} × " . self::nombre($item) . '. '
                    . self::textoDestino(['destino' => $destino, 'tienda_id' => $tiendaId, 'recogida' => 'ya', 'fecha' => null],
                                         self::entraAlInventario($item)),
            ]);
        });

        return $d->fresh();
    }

    /** Cambiar el día de la recogida, o pasarla a "cuando se le entregue el nuevo". */
    public static function reprogramar(Devolucion $d, array $data, Usuario $quien): Devolucion
    {
        if (! $d->porRecoger()) {
            self::fallar('Esa devolución ya se recibió.');
        }

        $recogida = $data['recogida'];
        $fecha    = null;
        if ($recogida === 'programada') {
            $fecha = Carbon::parse($data['fecha_recogida'])->toDateString();
            if (Carbon::parse($fecha)->lt(now('America/Bogota')->startOfDay())) {
                self::fallar('La recogida no puede quedar para un día que ya pasó.');
            }
        }

        $d->update(['recoger_el' => $fecha, 'recoger_con_entrega' => $recogida === 'con_entrega']);

        $item = OrdenItem::find($d->orden_item_id);
        OrdenMensaje::create([
            'orden_id'   => $d->orden_id,
            'usuario_id' => $quien->id,
            'mensaje'    => '🗓️ Recogida de ' . ($item ? self::nombre($item) : 'lo devuelto') . ': '
                . ($fecha ? 'se recoge el ' . Carbon::parse($fecha)->format('d/m/Y') . '.' : 'se recoge cuando se le entregue el nuevo.'),
        ]);

        return $d->fresh();
    }

    /** "Volvió al inventario de Norte", "Se recoge el 25/10 y va a la Fábrica"… */
    private static function textoDestino(array $plan, bool $entraAlInventario): string
    {
        $lugar = $plan['destino'] === 'tienda'
            ? (Tienda::find($plan['tienda_id'])?->nombre ?? 'la tienda')
            : null;

        if ($plan['recogida'] === 'ya') {
            if (! $lugar) return 'Sale como merma: no vuelve a la venta.';

            return $entraAlInventario
                ? "Volvió al inventario de {$lugar}."
                : "Quedó en {$lugar} (no es de catálogo: no entra al inventario).";
        }

        $cuando = $plan['recogida'] === 'programada'
            ? 'Se recoge el ' . Carbon::parse($plan['fecha'])->format('d/m/Y')
            : 'Se recoge cuando se le entregue el nuevo';

        return $lugar
            ? "{$cuando} y va a {$lugar}" . ($entraAlInventario ? ' (entra al inventario cuando llegue).' : '.')
            : "{$cuando} y sale como merma.";
    }

    /** A quien despacha: hay un mueble que traer de la casa del cliente. */
    private static function avisarRecogida(Devolucion $d, Orden $orden, string $nombre): void
    {
        $cuando = $d->recoger_el ? 'el ' . $d->recoger_el->format('d/m/Y') : 'cuando se le entregue el nuevo';
        $ids = Usuario::where('activo', true)->where('acceso_despacho', true)->pluck('id');

        foreach ($ids as $id) {
            try {
                NotificacionService::crear(
                    'despacho',
                    'Hay que recoger un producto',
                    "{$d->cantidad} × {$nombre} de " . ($orden->cliente?->nombre ?? 'el cliente')
                        . " (orden {$orden->referencia}): se recoge {$cuando}. Está en Despacho → Por recoger.",
                    ['orden_id' => $orden->id, 'devolucion_id' => $d->id],
                    (int) $id,
                );
            } catch (\Throwable) {
                // El aviso no puede tumbar el cambio, que ya se guardó.
            }
        }
    }

    // ── El reemplazo ─────────────────────────────────────────────────────────

    /**
     * El renglón nuevo. Mismo esquema que Nueva orden: de stock se aparta en
     * la tienda escogida (solo si hay libres de verdad); para fabricar o de
     * diseño especial nace con su producción pendiente.
     *
     * @param array<string,mixed> $r
     */
    private static function crearReemplazo(Orden $orden, array $r, string $motivo, Usuario $quien): OrdenItem
    {
        $cant   = (int) ($r['cantidad'] ?? 1);
        $precio = round((float) $r['precio_unitario'], 2);
        $fecha  = $r['fecha_entrega_prom'] ?? null;

        if ($r['tipo'] === 'diseno_especial') {
            $nombre = trim((string) ($r['nombre'] ?? ''));
            if ($nombre === '') self::fallar('Escribe qué mueble es el diseño especial.');
            if ($precio <= 0) self::fallar('Ponle precio al diseño especial: con $0 la orden quedaría esperando cotización.');

            $medidas = collect(['ancho', 'largo', 'alto'])
                ->map(fn ($k) => trim((string) ($r[$k] ?? '')))->filter()->implode(' × ');
            $specs = array_filter([
                'descripcion' => trim((string) ($r['detalles'] ?? '')),
                'medidas'     => $medidas,
                'madera'      => trim((string) ($r['madera'] ?? '')),
                'tela'        => trim((string) ($r['tela'] ?? '')),
            ], fn ($v) => $v !== '');

            $bocetos = array_values(array_filter((array) ($r['boceto_urls'] ?? [])));

            $atributos = [
                'orden_id'              => $orden->id,
                'producto_id'           => null,
                'nombre_custom'         => $nombre,
                'categoria_custom'      => $r['categoria'] ?? null,
                'cantidad'              => $cant,
                'precio_unitario'       => $precio,
                'es_personalizado'      => true,
                'fabricar_pedido'       => false,
                'es_restauracion'       => false,
                'specs_personalizacion' => $specs ?: null,
                'boceto_url'            => $bocetos[0] ?? null,
                'boceto_fotos'          => count($bocetos) > 1 ? $bocetos : null,
                'fecha_entrega_prom'    => $fecha,
            ];
            // La ficha que el cliente aprueba en la declaración de diseños
            // especiales: sale de lo mismo que se escribió aquí.
            if (Esquema::columna('orden_items', 'ficha_especial')) {
                $ficha = DeclaracionEspeciales::normalizarFicha([
                    'tipo_mueble' => $nombre,
                    'madera'      => $r['madera'] ?? null,
                    'tela'        => $r['tela'] ?? null,
                    'ancho'       => $r['ancho'] ?? null,
                    'largo'       => $r['largo'] ?? null,
                    'alto'        => $r['alto'] ?? null,
                    'detalles'    => $r['detalles'] ?? null,
                ]);
                if ($ficha) $atributos['ficha_especial'] = $ficha;
            }

            $nuevo = OrdenItem::create($atributos);
            self::aProduccion($nuevo, $fecha, $motivo);

            return $nuevo;
        }

        // ── De catálogo ──
        $producto = Producto::find($r['producto_id'] ?? 0);
        if (! $producto) self::fallar('Escoge el producto nuevo.');

        $varianteId = ! empty($r['variante_id']) ? (int) $r['variante_id'] : null;
        $comboId    = ! empty($r['combo_config_id']) ? (int) $r['combo_config_id'] : null;
        if ($varianteId && ! ProductoVariante::whereKey($varianteId)->where('producto_id', $producto->id)->exists()) {
            self::fallar('Esa tela o variante no es de ese producto.');
        }
        if ($comboId && ! ProductoVarianteConfig::whereKey($comboId)->where('producto_id', $producto->id)->exists()) {
            self::fallar('Esa opción no es de ese producto.');
        }

        $fabricar = (bool) ($r['fabricar'] ?? false);
        $tiendaId = $fabricar ? null : (int) ($r['tienda_id'] ?? 0);
        if (! $fabricar) {
            if (! $tiendaId || ! Tienda::whereKey($tiendaId)->exists()) {
                self::fallar('Escoge de qué tienda sale el producto nuevo, o márcalo para fabricar.');
            }
            $libre = GarantiaService::stockLibre($producto->id, $varianteId, $comboId, $tiendaId);
            if ($libre < $cant) {
                $tienda = Tienda::find($tiendaId)?->nombre ?? 'esa tienda';
                self::fallar("En {$tienda} no hay suficientes libres de \"{$producto->nombre}\": hay " . max(0, $libre)
                    . " y se necesitan {$cant}. Escoge otra tienda o mándalo a fabricar.");
            }
        }

        $varianteDetalle = OrdenItem::detalleDeVariante(null, $comboId, $varianteId);
        $specs = null;
        if ($varianteId && ! $fabricar && $v = ProductoVariante::find($varianteId)) {
            $specs = ['variante_marca' => $v->marca_tela, 'variante_color' => $v->nombre_color];
        }
        // Lo que se manda a fabricar lleva sus notas para el taller, en el
        // mismo sitio que Nueva orden: salen en la tarjeta de Producción.
        $notasTaller = $fabricar ? trim((string) ($r['notas'] ?? '')) : '';
        if ($notasTaller !== '') $specs = ['notas' => $notasTaller];

        $nuevo = OrdenItem::create([
            'orden_id'              => $orden->id,
            'producto_id'           => $producto->id,
            'variante_id'           => $varianteId,
            'combo_config_id'       => $comboId,
            'variante_detalle'      => $varianteDetalle,
            'cantidad'              => $cant,
            'precio_unitario'       => $precio,
            'es_personalizado'      => $fabricar,
            'fabricar_pedido'       => $fabricar,
            'es_restauracion'       => false,
            'specs_personalizacion' => $specs,
            'tienda_origen_id'      => $fabricar ? null : ($tiendaId !== (int) $orden->tienda_id ? $tiendaId : null),
            'fecha_entrega_prom'    => $fecha,
        ]);

        if ($fabricar) {
            self::aProduccion($nuevo, $fecha, $motivo);
            // La tela se aparta sin frenar el cambio: si no alcanza, los
            // metros quedan en negativo y en Telas se ve que hay que comprar.
            ConsumoTelas::sincronizarItem($nuevo, $orden, false);
        } else {
            GarantiaService::apartar($nuevo, $tiendaId, $quien, "Cambio de producto — orden {$orden->referencia}");
        }

        return $nuevo;
    }

    private static function aProduccion(OrdenItem $nuevo, ?string $fecha, string $motivo): void
    {
        Produccion::create([
            'orden_item_id'    => $nuevo->id,
            'fecha_inicio'     => now()->toDateString(),
            'fecha_compromiso' => $fecha,
            'estado'           => 'pendiente',
            'motivo_retraso'   => 'Cambio de producto: ' . $motivo,
        ]);
    }

    /**
     * Lo devuelto queda disponible otra vez en esa tienda. Es lo contrario de
     * lo que hizo la entrega (EntregaService::sacarDelInventario), sin la
     * reserva: ya no es de esta orden.
     */
    private static function volverAlInventario(OrdenItem $item, Orden $orden, int $tiendaId, int $n, Usuario $quien): void
    {
        Inventario::firstOrCreate(
            ['producto_id' => $item->producto_id, 'tienda_id' => $tiendaId],
            ['cantidad_disponible' => 0, 'cantidad_reservada' => 0],
        )->increment('cantidad_disponible', $n);

        // Al que se le cambió la tela no se le devuelve a su variante: ya no
        // es de esa tela. Entra al stock base y en Inventario le asignan la
        // tela que tiene ahora.
        if ($item->variante_id && ! $item->retapizar) {
            InventarioVariante::where('variante_id', $item->variante_id)->where('tienda_id', $tiendaId)
                ->increment('cantidad_disponible', $n);
            if ($item->combo_config_id) {
                InventarioVarianteCombinacion::where('variante_id', $item->variante_id)
                    ->where('config_id', $item->combo_config_id)->where('tienda_id', $tiendaId)
                    ->increment('cantidad_disponible', $n);
            }
        }
        if (! $item->retapizar && Esquema::tabla('inventario_variante_configs')) {
            StockMedida::mover($item->combo_config_id, $tiendaId, $n);
        }

        InventarioMovimiento::create([
            'producto_id' => $item->producto_id,
            'tienda_id'   => $tiendaId,
            'tipo'        => 'entrada',
            'cantidad'    => $n,
            'motivo'      => "Devolución para cambio — orden {$orden->referencia}"
                . ($item->retapizar ? " (retapizado: {$item->variante_texto})" : ''),
            'usuario_id'  => $quien->id,
        ]);

        try { event(new \App\Events\InventarioActualizado($tiendaId, (int) $item->producto_id, 'entrada')); } catch (\Throwable) {}
    }

    // ── Rastro y avisos ──────────────────────────────────────────────────────

    /** Queda escrito en el hilo de la orden, donde la gente ya mira. */
    private static function anotar(Orden $orden, Usuario $quien, array $data, int $n, string $nombreViejo, string $fecha,
                                   array $plan, bool $entraAlInventario, ?OrdenItem $nuevo, ?float $diferencia): void
    {
        $msg = "🔁 Se devolvió {$n} × {$nombreViejo} el " . Carbon::parse($fecha)->format('d/m/Y')
             . ". Motivo: {$data['motivo']}. "
             . self::textoDestino($plan, $entraAlInventario) . ' ';

        if (! $nuevo) {
            $msg .= 'La orden vuelve a estar abierta para el cambio.';
        } else {
            $msg .= "Se cambia por {$nuevo->cantidad} × " . self::nombre($nuevo) . self::comoSale($nuevo, $orden) . '. '
                  . (abs((float) $diferencia) < 0.01 ? 'Sin diferencia de valor.'
                    : ($diferencia > 0
                        ? 'El valor de la venta sube $' . number_format($diferencia, 0, ',', '.') . '.'
                        : 'El valor de la venta baja $' . number_format(-$diferencia, 0, ',', '.') . '.'));
        }

        OrdenMensaje::create([
            'orden_id'   => $orden->id,
            'usuario_id' => $quien->id,
            'mensaje'    => $msg,
            'imagen_url' => $data['foto_url'] ?? null,
        ]);
    }

    /** Al taller le llega trabajo nuevo: que se sepa sin tener que buscarlo. */
    private static function avisarProduccion(Orden $orden, OrdenItem $nuevo): void
    {
        $destinatarios = Usuario::where('activo', true)->where('gestiona_produccion', true)->pluck('id');

        foreach ($destinatarios as $id) {
            try {
                NotificacionService::crear(
                    'produccion',
                    'Cambio de producto para fabricar',
                    self::nombre($nuevo) . ' para ' . ($orden->cliente?->nombre ?? 'el cliente')
                        . " (orden {$orden->referencia}): el cliente cambió lo que se le entregó.",
                    ['orden_id' => $orden->id],
                    (int) $id,
                );
            } catch (\Throwable) {
                // Un aviso que no sale no puede tumbar el cambio, que ya se guardó.
            }
        }
    }

    private static function comoSale(OrdenItem $nuevo, Orden $orden): string
    {
        if ($nuevo->tipo_item === 'diseno_especial') return ' (diseño especial, se manda a fabricar)';
        if ($nuevo->es_personalizado)                return ' (se manda a fabricar)';

        $tiendaId = $nuevo->tienda_origen_id ?? $orden->tienda_id;

        return ' (de ' . (Tienda::find($tiendaId)?->nombre ?? 'la tienda') . ')';
    }

    private static function nombre(OrdenItem $item): string
    {
        $base = $item->nombre_custom ?: ($item->producto?->nombre ?? Producto::find($item->producto_id)?->nombre ?? 'un producto');

        return $item->variante_detalle ? "{$base} ({$item->variante_detalle})" : $base;
    }

    private static function fallar(string $mensaje): never
    {
        throw new HttpResponseException(response()->json(['message' => $mensaje], 422));
    }
}
