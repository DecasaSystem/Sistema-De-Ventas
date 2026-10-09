<?php

namespace App\Services;

use App\Http\Controllers\ComisionController;
use App\Http\Controllers\ProduccionController;
use App\Models\DespachoItem;
use App\Models\EntregaLinea;
use App\Models\Garantia;
use App\Models\Inventario;
use App\Models\InventarioMovimiento;
use App\Models\InventarioVariante;
use App\Models\InventarioVarianteCombinacion;
use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\OrdenMensaje;
use App\Models\Produccion;
use App\Models\ProduccionPaso;
use App\Models\Producto;
use App\Models\ProductoVariante;
use App\Models\ProductoVarianteConfig;
use App\Models\Tienda;
use App\Models\TipoProceso;
use App\Models\Usuario;
use App\Support\FestivosColombia;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Garantías: lo que se daña después de entregado.
 *
 * Una sola puerta para todo lo que pasa con una garantía, para que el stock,
 * el taller y la orden se muevan siempre igual, la pida quien la pida.
 *
 * La idea que lo sostiene todo: **reactivar el producto**. Cuando se decide
 * arreglarlo en el taller, esas unidades vuelven a estar "por entregar" en la
 * orden (`cantidad_entregada` baja) y el mueble recorre el camino de siempre:
 * pasos del taller → listo → entrega con acta y fotos. No hay un flujo
 * paralelo de "entregas de garantía": se reutiliza todo lo que ya existe, y la
 * orden muestra sola "en producción" / "lista para entrega" mientras tanto.
 *
 * Lo que distingue a esas unidades (`cantidad_en_garantia`) es que ya salieron
 * del inventario la primera vez: al volverlas a entregar no se descuenta otra
 * vez (ver `EntregaService::entregar`).
 *
 * El cambio por otro producto (o por otra unidad igual de inventario) no
 * reactiva el renglón viejo: se agrega uno nuevo con el reemplazo, apartado en
 * la tienda que de verdad lo tiene, y el viejo deja de cobrarse. Así la orden
 * cuenta la historia completa —qué se compró, qué se cambió y por qué— y el
 * inventario se mueve por el camino normal.
 */
class GarantiaService
{
    /** Decide quien gestiona el taller o un supervisor (como las devoluciones). */
    public static function puedeDecidir(?Usuario $u): bool
    {
        return $u && ($u->rol === 'supervisor' || (bool) $u->gestiona_produccion);
    }

    /**
     * Cambiar por otro producto mueve plata (el total de la orden y la
     * comisión), y el precio lo decide quien aprueba: eso es de un supervisor.
     */
    public static function puedeDecidirConPlata(?Usuario $u): bool
    {
        return $u && $u->rol === 'supervisor';
    }

    // ── Reportar ─────────────────────────────────────────────────────────────

    /**
     * Cuántas unidades de este producto se pueden reclamar hoy: las que el
     * cliente tiene en la casa y no están ya en otra garantía sin resolver.
     */
    public static function unidadesReclamables(OrdenItem $item): int
    {
        if (! $item->estaVivo()) return 0;

        // Las que ya se reactivaron (taller) bajaron de `cantidad_entregada`;
        // las que siguen esperando dictamen, la visita o que devuelvan el
        // producto para el reembolso, todavía no.
        $enTramite = (int) Garantia::where('orden_item_id', $item->id)
            ->whereIn('estado', ['pendiente', 'a_domicilio', 'por_devolver'])
            ->sum('cantidad');

        return max(0, (int) $item->cantidad_entregada - $enTramite);
    }

    /**
     * La última vez que se le entregó este producto al cliente, en hora de
     * Bogotá. Es desde donde corre la garantía (anexo, numeral 2).
     */
    public static function fechaEntregaDe(OrdenItem $item): ?Carbon
    {
        $ultima = DB::table('entrega_lineas as el')
            ->join('despacho_items as di', 'di.id', '=', 'el.despacho_item_id')
            ->where('el.orden_item_id', $item->id)
            ->whereIn('el.resultado', EntregaLinea::SE_QUEDO)
            ->whereNotNull('di.entregado_at')
            ->max('di.entregado_at');

        return $ultima
            ? Carbon::parse($ultima, 'UTC')->setTimezone('America/Bogota')->startOfDay()
            : null;
    }

    /** @param array<string,mixed> $data  ya validado por el controlador */
    public static function registrar(OrdenItem $item, array $data, Usuario $quien): Garantia
    {
        $orden = $item->orden;

        if (in_array($orden->estado, ['cancelado', 'borrador', 'cotizacion'], true)) {
            self::fallar('Esta orden no es una venta viva: no tiene garantía.');
        }
        $cantidad    = (int) $data['cantidad'];
        $reclamables = self::unidadesReclamables($item);
        if ($reclamables <= 0) {
            self::fallar((int) $item->cantidad_entregada <= 0
                ? 'Ese producto todavía no se ha entregado. Si se dañó antes de salir, repórtalo como producto dañado.'
                : 'Las unidades entregadas de ese producto ya están en una garantía sin resolver.');
        }
        if ($cantidad > $reclamables) {
            self::fallar("De ese producto se pueden reclamar {$reclamables}, no {$cantidad}.");
        }

        $hoy      = now('America/Bogota')->startOfDay();
        $tipo     = $data['tipo_dano'];
        $linea    = $tipo === 'madera' ? ($data['linea'] ?? 'elite_promocional') : null;
        $entrega  = self::fechaEntregaDe($item);
        $vence    = Garantia::venceEl($entrega, $tipo, $linea);

        $garantia = DB::transaction(function () use ($item, $orden, $data, $quien, $cantidad, $hoy, $tipo, $linea, $entrega, $vence) {
            $g = Garantia::create([
                'orden_id'            => $orden->id,
                'orden_item_id'       => $item->id,
                'cantidad'            => $cantidad,
                'tipo_dano'           => $tipo,
                'linea'               => $linea,
                'motivo'              => $data['motivo'],
                'fotos'               => array_values(array_filter($data['fotos'] ?? [])) ?: null,
                'donde_esta'          => $data['donde_esta'] ?? 'casa_cliente',
                'preferencia_cliente' => $data['preferencia_cliente'] ?? null,
                'fecha_reporte'       => $hoy->toDateString(),
                'fecha_entrega'       => $entrega?->toDateString(),
                'vence_el'            => $vence?->toDateString(),
                'responder_antes_de'  => FestivosColombia::sumarHabiles($hoy, 15)->toDateString(),
                'reportado_por_id'    => $quien->id,
                'estado'              => 'pendiente',
            ]);

            $vigencia = $vence
                ? ($hoy->lte($vence) ? " Garantía vigente hasta el {$vence->format('d/m/Y')}." : " ⚠️ La garantía venció el {$vence->format('d/m/Y')}.")
                : '';
            self::anotar($orden, $quien,
                "🛡️ Garantía reportada: {$cantidad} × " . self::nombre($item) . ". {$data['motivo']}.{$vigencia}",
                $g->fotos[0] ?? null);

            return $g;
        });

        self::avisarReporte($garantia->fresh(['orden.cliente', 'item.producto']));

        return $garantia;
    }

    // ── Decidir ──────────────────────────────────────────────────────────────

    /**
     * El dictamen. Se puede decidir lo que espera dictamen, y también lo que
     * se mandó a domicilio: si allá no se pudo arreglar, se trae al taller o
     * se cambia sin abrir otra garantía.
     *
     * @param array<string,mixed> $data  ya validado por el controlador
     */
    public static function decidir(Garantia $g, array $data, Usuario $quien): Garantia
    {
        // También lo aprobado para reembolso mientras no haya salido la plata:
        // el cliente puede cambiar de idea antes de devolver el producto.
        if (! in_array($g->estado, ['pendiente', 'a_domicilio', 'por_devolver'], true)) {
            self::fallar('Esta garantía ya tiene decisión.');
        }
        // Mientras esperaba dictamen alguien lo cambió por otro producto o lo
        // devolvió por otro camino: ya no hay nada que arreglar ahí.
        if ($data['decision'] !== 'no_procede' && ! $g->item?->estaVivo()) {
            self::fallar('Ese producto ya no está vivo en la orden (se devolvió o se cambió). Ciérrala como "no procede" con una nota.');
        }

        DB::transaction(function () use ($g, $data, $quien) {
            $g->fill([
                'decision'        => $data['decision'],
                'decidido_por_id' => $quien->id,
                'decidido_at'     => now(),
                'notas_decision'  => $data['notas'] ?? null,
            ]);

            match ($data['decision']) {
                'taller'       => self::alTaller($g, $data, $quien),
                'domicilio'    => self::aDomicilio($g, $data, $quien),
                'cambio_mismo' => self::cambiarPorElMismo($g, $data, $quien),
                'cambio_otro'  => self::cambiarPorOtro($g, $data, $quien),
                'reembolso'    => self::aprobarReembolso($g, $data, $quien),
                'no_procede'   => self::noProcede($g, $data, $quien),
            };

            $g->save();
        });

        self::avisarDecision($g->fresh(['orden.cliente', 'item.producto', 'visitaPor']), $quien);

        return $g;
    }

    /**
     * Se arregla en el taller.
     *
     * El producto se reactiva en la orden y su producción se reabre —la
     * misma, para que el arreglo quede pegado a la historia de cómo se hizo—.
     * Si el mueble todavía está en la casa del cliente, los pasos se arman
     * cuando llegue (`recibirEnTaller`): no tiene sentido ponerle a un
     * ebanista en "Mis pasos" una pieza que no tiene enfrente.
     */
    private static function alTaller(Garantia $g, array $data, Usuario $quien): void
    {
        $g->procesos_reparacion = self::procesosValidos($data['procesos'] ?? []);
        self::reactivar($g, 'Garantía: ' . $g->motivo);

        $g->estado = 'por_recoger';
        self::anotar($g->orden, $quien, '🛡️ Garantía de ' . self::nombre($g->item) . ': se arregla en el taller.'
            . ($g->donde_esta === 'tienda' ? '' : ' Hay que recogerlo en la casa del cliente.')
            . self::notas($g));

        // Ya está en la tienda: entra al taller de una vez.
        if ($g->donde_esta === 'tienda') {
            self::recibir($g, $quien);
        }
    }

    /** Alguien va a la casa del cliente. Queda quién y cuándo. */
    private static function aDomicilio(Garantia $g, array $data, Usuario $quien): void
    {
        $g->fill([
            'estado'        => 'a_domicilio',
            'visita_por_id' => $data['visita_por_id'],
            'visita_fecha'  => $data['visita_fecha'],
        ]);

        $quienVa = Usuario::find($data['visita_por_id'])?->nombre ?? 'alguien del taller';
        self::anotar($g->orden, $quien, '🛡️ Garantía de ' . self::nombre($g->item)
            . ": se arregla en la casa del cliente. Va {$quienVa} el "
            . Carbon::parse($data['visita_fecha'])->format('d/m/Y') . '.' . self::notas($g));
    }

    /**
     * Otra unidad igual.
     *
     * Lo que se fabrica se hace de nuevo: es el mismo renglón reactivado, con
     * su producción vuelta a empezar (todos los procesos que se marquen). De
     * catálogo se aparta otra unidad en la tienda que la tenga —o se manda a
     * fabricar si no hay en ninguna— como renglón nuevo al mismo precio: la
     * orden no cambia de valor.
     */
    private static function cambiarPorElMismo(Garantia $g, array $data, Usuario $quien): void
    {
        $item = $g->item;
        $g->estado = 'cambio';

        if ($item->vaAlTaller()) {
            $g->procesos_reparacion = self::procesosValidos($data['procesos'] ?? []);
            self::reactivar($g, 'Garantía, se hace de nuevo: ' . $g->motivo);
            self::armarPasos($g, $quien);
            self::anotar($g->orden, $quien, '🛡️ Garantía de ' . self::nombre($item)
                . ': se fabrica de nuevo. El dañado se recoge al entregar el nuevo.' . self::notas($g));

            return;
        }

        $nuevo = self::crearReemplazo($g, [
            'producto_id'     => $item->producto_id,
            'variante_id'     => $item->variante_id,
            'combo_config_id' => $item->combo_config_id,
            'tienda_id'       => $data['tienda_id'] ?? null,
            'fabricar'        => (bool) ($data['fabricar'] ?? false),
        ], (float) $item->precio_unitario, $quien);

        self::anotar($g->orden, $quien, '🛡️ Garantía de ' . self::nombre($item) . ': se cambia por otra unidad igual'
            . ($nuevo->es_personalizado ? ', que se manda a fabricar' : ' de ' . self::nombreTienda($nuevo, $g->orden))
            . '. El dañado se recoge al entregar el nuevo.' . self::notas($g));
    }

    /**
     * Otro producto, al precio que decida quien aprueba (por defecto el de
     * lista, que pone la pantalla). Lo dañado deja de cobrarse y lo nuevo se
     * suma: si vale más, el cliente paga la diferencia; si vale menos, le
     * queda a favor. La comisión sigue al valor, como en cualquier cambio.
     */
    private static function cambiarPorOtro(Garantia $g, array $data, Usuario $quien): void
    {
        $item = $g->item;
        $g->estado = 'cambio';

        $nuevo = self::crearReemplazo($g, [
            'producto_id'     => $data['producto_id'],
            'variante_id'     => $data['variante_id'] ?? null,
            'combo_config_id' => $data['combo_config_id'] ?? null,
            'tienda_id'       => $data['tienda_id'] ?? null,
            'fabricar'        => (bool) ($data['fabricar'] ?? false),
        ], (float) $data['precio_unitario'], $quien);

        $dif = (float) $g->diferencia_valor;
        self::anotar($g->orden, $quien, '🛡️ Garantía de ' . self::nombre($item) . ': se cambia por '
            . self::nombre($nuevo) . ($nuevo->es_personalizado ? ' (se manda a fabricar)' : ' de ' . self::nombreTienda($nuevo, $g->orden))
            . '. ' . (abs($dif) < 0.01 ? 'Sin diferencia de valor.'
                : ($dif > 0 ? 'El cliente paga la diferencia: $' . number_format($dif, 0, ',', '.') . '.'
                            : 'Le quedan a favor $' . number_format(-$dif, 0, ',', '.') . '.'))
            . self::notas($g));
    }

    /**
     * Se le devuelve la plata y el cliente devuelve el producto.
     *
     * Aquí solo se aprueba y se fija cuánto (por defecto lo que pagó por esas
     * unidades; el supervisor puede poner otro valor). La plata NO sale
     * todavía: sale cuando el producto llega a la tienda
     * (`recibirDevolucion`). Así no se devuelve plata sin tener el mueble.
     */
    private static function aprobarReembolso(Garantia $g, array $data, Usuario $quien): void
    {
        $g->fill([
            'estado'          => 'por_devolver',
            'monto_reembolso' => round((float) $data['monto'], 2),
        ]);

        self::anotar($g->orden, $quien, '🛡️ Garantía de ' . self::nombre($g->item) . ': se le devuelve la plata ($'
            . number_format((float) $data['monto'], 0, ',', '.') . ') cuando devuelva el producto.' . self::notas($g));
    }

    /**
     * Llegó lo que devolvió el cliente: sale la plata y lo devuelto deja de
     * ser venta.
     *
     *  - El renglón deja de cobrarse (entero queda como rastro; si era parte,
     *    esas unidades salen de él) y el total de la orden baja. La meta de la
     *    tienda y la comisión pendiente lo siguen; una comisión ya pagada no
     *    se toca (decisión del dueño, 2026-10-09).
     *  - La plata sale como un pago NEGATIVO de tipo `reembolso`, a nombre de
     *    quien recibió la venta y en su tienda: si fue en efectivo baja la
     *    misma caja por donde entró; si fue transferencia, no toca la caja. El
     *    saldo, la cartera y el 50 % de la comisión lo cuentan solos.
     *  - Lo devuelto vuelve al inventario de una tienda (si sirve) o sale
     *    como merma: ya había salido del inventario al entregarlo.
     *
     * @param array{metodo:string, destino:string, tienda_id?:int|null, referencia?:string|null, monto?:float|null} $data
     */
    public static function recibirDevolucion(Garantia $g, array $data, Usuario $quien): Garantia
    {
        if ($g->estado !== 'por_devolver') {
            self::fallar('Esta garantía no está esperando que devuelvan el producto.');
        }

        DB::transaction(function () use ($g, $data, $quien) {
            $orden = Orden::lockForUpdate()->findOrFail($g->orden_id);
            $item  = OrdenItem::lockForUpdate()->findOrFail($g->orden_item_id);
            $n     = (int) $g->cantidad;
            $monto = round((float) ($data['monto'] ?? $g->monto_reembolso), 2);

            if (! $item->estaVivo() || (int) $item->cantidad_entregada < $n) {
                self::fallar('Ese producto ya no tiene tantas unidades entregadas en la orden: revisa si se cambió o se deshizo la entrega.');
            }
            if ($monto > $orden->totalPagado() + 0.01) {
                self::fallar('No se puede devolver más de lo que el cliente ha pagado ($'
                    . number_format($orden->totalPagado(), 0, ',', '.') . ').');
            }

            // 1. Lo devuelto: a una tienda o merma.
            $destino  = $data['destino'];
            $tiendaId = null;
            if ($destino === 'inventario') {
                if (! $item->producto_id) {
                    self::fallar('Ese producto no es de catálogo: no tiene inventario al cual volver. Márcalo como merma.');
                }
                $tiendaId = (int) ($data['tienda_id'] ?? 0);
                if (! $tiendaId || ! Tienda::whereKey($tiendaId)->exists()) {
                    self::fallar('Escoge a qué tienda vuelve el producto.');
                }
                self::volverAlInventario($item, $orden, $tiendaId, $n, $quien);
            }

            // 2. Deja de cobrarse.
            if ($n >= (int) $item->cantidad) {
                $item->update(['devuelto_en' => now('America/Bogota')->toDateString(), 'motivo_devolucion' => 'Garantía, reembolso: ' . $g->motivo]);
            } else {
                $item->update([
                    'cantidad'           => (int) $item->cantidad - $n,
                    'cantidad_entregada' => (int) $item->cantidad_entregada - $n,
                ]);
            }
            $antes = (float) $orden->valor_total;
            $orden->recalcularTotal();
            if (abs((float) $orden->valor_total - $antes) >= 0.01) {
                ComisionController::sincronizarValorOrden($orden->fresh());
            }

            // 3. La plata.
            $pago = null;
            if ($monto > 0) {
                $pago = \App\Models\Pago::create([
                    'orden_id'    => $orden->id,
                    'vendedor_id' => $orden->vendedor_id,
                    'tienda_id'   => $orden->tienda_id,
                    'tipo'        => 'reembolso',
                    'monto'       => -$monto,
                    'metodo'      => $data['metodo'],
                    'referencia'  => $data['referencia'] ?? null,
                    'notas'       => "Reembolso por garantía #{$g->id} — lo entregó {$quien->nombre}",
                ]);
            }

            // 4. La garantía se cierra; la orden queda en lo que le toca. Si ya
            //    no le queda nada al cliente, la venta no existió.
            $g->fill([
                'estado'             => 'resuelta',
                'monto_reembolso'    => $monto,
                'pago_reembolso_id'  => $pago?->id,
                'destino_devuelto'   => $destino,
                'tienda_devuelto_id' => $tiendaId,
                'resuelta_at'        => now(),
                'resuelta_por_id'    => $quien->id,
            ])->save();

            $orden->refresh()->load('items');
            if ($orden->items->filter->estaVivo()->isEmpty()) {
                $orden->update(['estado' => 'cancelado']);
            } else {
                self::ponerOrdenAlDia($orden);
            }

            self::anotar($orden, $quien, '💸 Garantía con reembolso: el cliente devolvió ' . $n . ' × ' . self::nombre($item)
                . ' y se le devolvieron $' . number_format($monto, 0, ',', '.') . " ({$data['metodo']}). "
                . ($destino === 'inventario'
                    ? 'Volvió al inventario de ' . (Tienda::find($tiendaId)?->nombre ?? 'la tienda') . '.'
                    : 'Sale como merma.')
                . ' Deja de contar como venta; una comisión ya pagada no cambia.');
        });

        self::avisarFacturacionReembolso($g->fresh(['orden.cliente']));

        return $g;
    }

    /** Lo devuelto queda disponible otra vez en esa tienda. */
    private static function volverAlInventario(OrdenItem $item, Orden $orden, int $tiendaId, int $n, Usuario $quien): void
    {
        $inv = Inventario::firstOrCreate(
            ['producto_id' => $item->producto_id, 'tienda_id' => $tiendaId],
            ['cantidad_disponible' => 0, 'cantidad_reservada' => 0],
        );
        $inv->increment('cantidad_disponible', $n);

        // Al que se le cambió la tela no se le devuelve a su variante: ya no es
        // de esa tela (como en `cambiarProducto`).
        if ($item->variante_id && ! $item->retapizar) {
            InventarioVariante::where('variante_id', $item->variante_id)->where('tienda_id', $tiendaId)
                ->increment('cantidad_disponible', $n);
        }

        InventarioMovimiento::create([
            'producto_id' => $item->producto_id,
            'tienda_id'   => $tiendaId,
            'tipo'        => 'entrada',
            'cantidad'    => $n,
            'motivo'      => "Devuelto por garantía con reembolso — orden {$orden->referencia}",
            'usuario_id'  => $quien->id,
        ]);

        try { event(new \App\Events\InventarioActualizado($tiendaId, (int) $item->producto_id, 'entrada')); } catch (\Throwable) {}
    }

    /** Lo facturado cambió: a facturación le toca revisar (nota crédito). */
    private static function avisarFacturacionReembolso(Garantia $g): void
    {
        $orden = $g->orden;
        if (! $orden || (float) $g->monto_reembolso <= 0) return;

        foreach (Usuario::where('rol', 'vendedor')->where('facturacion', true)
                     ->where('tienda_default_id', $orden->tienda_id)->get() as $u) {
            NotificacionService::crear(
                'facturar',
                'Reembolso por garantía: revisar la factura',
                "Orden {$orden->referencia} de " . ($orden->cliente?->nombre ?? 'el cliente')
                    . ': se devolvieron $' . number_format((float) $g->monto_reembolso, 0, ',', '.') . ' por garantía.',
                ['orden_id' => $orden->id, 'garantia_id' => $g->id],
                $u->id,
            );
        }
    }

    private static function noProcede(Garantia $g, array $data, Usuario $quien): void
    {
        $g->fill([
            'estado'            => 'no_procede',
            'causal_no_procede' => $data['causal'],
            'resuelta_at'       => now(),
            'resuelta_por_id'   => $quien->id,
        ]);

        self::anotar($g->orden, $quien, '🛡️ Garantía de ' . self::nombre($g->item) . ': no procede — '
            . (Garantia::CAUSALES[$data['causal']] ?? $data['causal']) . '.' . self::notas($g)
            . ' Si el cliente quiere el arreglo pagado, se le hace una orden de restauración.');
    }

    // ── Después del dictamen ─────────────────────────────────────────────────

    /** El mueble llegó a la fábrica: arranca el arreglo. */
    public static function recibirEnTaller(Garantia $g, Usuario $quien): Garantia
    {
        if ($g->estado !== 'por_recoger') {
            self::fallar('Esta garantía no está esperando que llegue el mueble.');
        }

        DB::transaction(fn () => self::recibir($g, $quien));

        return $g;
    }

    private static function recibir(Garantia $g, Usuario $quien): void
    {
        $g->fill([
            'estado'                => 'en_taller',
            'recibido_en_taller_at' => now(),
            'recibido_por_id'       => $quien->id,
        ]);
        $g->save();

        self::armarPasos($g, $quien);

        if ($g->donde_esta !== 'tienda') {
            self::anotar($g->orden, $quien, '🛡️ ' . self::nombre($g->item)
                . ' llegó al taller por garantía. El anexo promete devolverlo antes del '
                . $g->devolverAntesDe()->format('d/m/Y') . '.');
        }
    }

    /** Lo arreglaron en la casa: se cierra con lo que encontraron e hicieron. */
    public static function registrarVisita(Garantia $g, array $data, Usuario $quien): Garantia
    {
        if ($g->estado !== 'a_domicilio') {
            self::fallar('Esta garantía no tiene una visita pendiente.');
        }

        DB::transaction(function () use ($g, $data, $quien) {
            $g->fill([
                'estado'          => 'resuelta',
                'visita_notas'    => $data['notas'],
                'visita_fotos'    => array_values(array_filter($data['fotos'] ?? [])) ?: null,
                'visita_fecha'    => $data['fecha'] ?? $g->visita_fecha ?? now('America/Bogota')->toDateString(),
                'resuelta_at'     => now(),
                'resuelta_por_id' => $quien->id,
            ])->save();

            self::anotar($g->orden, $quien, '✅ Garantía resuelta en la casa del cliente: '
                . self::nombre($g->item) . ". {$data['notas']}", $g->visita_fotos[0] ?? null);
        });

        return $g;
    }

    // ── Enganches con la entrega ─────────────────────────────────────────────

    /**
     * Cierra las garantías que esta entrega terminó de resolver.
     *
     *  - Cambio con renglón nuevo: cuando el reemplazo quedó entregado entero.
     *  - Taller (o lo fabricado de nuevo): cuando las unidades en garantía de
     *    ese producto volvieron a la casa. Si hay varias abiertas del mismo
     *    producto se cierran de la más vieja a la más nueva, según cuántas
     *    unidades llegaron.
     *
     * @return array<int,int>  ids de las garantías cerradas
     */
    public static function alEntregar(DespachoItem $entrega, int $quienId): array
    {
        if (! self::hayTabla()) return [];

        $itemIds = $entrega->lineas()->whereIn('resultado', EntregaLinea::SE_QUEDO)
            ->pluck('orden_item_id')->unique()->all();
        if (! $itemIds) return [];

        $cerradas = [];

        foreach (Garantia::where('estado', 'cambio')->whereIn('orden_item_nuevo_id', $itemIds)->get() as $g) {
            $nuevo = OrdenItem::find($g->orden_item_nuevo_id);
            if ($nuevo && $nuevo->pendienteEntregar() === 0) {
                $cerradas[] = self::cerrarConEntrega($g, $entrega, $quienId);
            }
        }

        foreach ($itemIds as $itemId) {
            $abiertas = Garantia::where('orden_item_id', $itemId)
                ->whereIn('estado', ['por_recoger', 'en_taller', 'cambio'])
                ->whereNull('orden_item_nuevo_id')
                ->orderBy('id')->get();
            if ($abiertas->isEmpty()) continue;

            $quedan     = (int) OrdenItem::whereKey($itemId)->value('cantidad_en_garantia');
            $llegaron   = (int) $abiertas->sum('cantidad') - $quedan;
            foreach ($abiertas as $g) {
                if ($llegaron < (int) $g->cantidad) break;
                $llegaron  -= (int) $g->cantidad;
                $cerradas[] = self::cerrarConEntrega($g, $entrega, $quienId);
            }
        }

        return $cerradas;
    }

    /** Deshicieron la entrega que las cerró: vuelven a quedar abiertas. */
    public static function alRevertir(DespachoItem $entrega): void
    {
        if (! self::hayTabla()) return;

        foreach (Garantia::where('despacho_item_id', $entrega->id)->where('estado', 'resuelta')->get() as $g) {
            $g->update([
                'estado'           => $g->decision === 'taller' ? 'en_taller' : 'cambio',
                'despacho_item_id' => null,
                'resuelta_at'      => null,
                'resuelta_por_id'  => null,
            ]);
        }
    }

    /**
     * ¿Hay una garantía sobre lo que llevó esta entrega, registrada después de
     * ella? Entonces no se puede deshacer a ciegas: el cliente ya reclamó por
     * ese mueble y la garantía cuenta con que se le entregó.
     */
    public static function bloqueaDeshacer(DespachoItem $entrega): bool
    {
        if (! self::hayTabla() || ! $entrega->entregado_at) return false;

        $itemIds = $entrega->lineas()->pluck('orden_item_id')->all();

        return Garantia::whereIn('orden_item_id', $itemIds)
            ->where('estado', '!=', 'no_procede')
            ->where('created_at', '>=', $entrega->entregado_at)
            ->exists();
    }

    private static function cerrarConEntrega(Garantia $g, DespachoItem $entrega, int $quienId): int
    {
        $g->update([
            'estado'           => 'resuelta',
            'despacho_item_id' => $entrega->id,
            'resuelta_at'      => now(),
            'resuelta_por_id'  => $quienId,
        ]);

        $g->loadMissing('item.producto', 'itemNuevo.producto');
        OrdenMensaje::create([
            'orden_id'   => $g->orden_id,
            'usuario_id' => $quienId,
            'mensaje'    => '✅ Garantía resuelta: se le entregó '
                . ($g->itemNuevo ? self::nombre($g->itemNuevo) . ' en cambio de ' . self::nombre($g->item) : self::nombre($g->item) . ' arreglado')
                . '.',
        ]);

        return (int) $g->id;
    }

    // ── Piezas internas ──────────────────────────────────────────────────────

    /**
     * Las unidades vuelven a estar "por entregar" y la pieza vuelve al
     * taller. La producción es la misma (una por renglón); si era de catálogo
     * y nunca tuvo, se le crea una para el arreglo.
     */
    private static function reactivar(Garantia $g, string $motivo): void
    {
        $item = OrdenItem::lockForUpdate()->findOrFail($g->orden_item_id);
        $n    = (int) $g->cantidad;

        if ((int) $item->cantidad_entregada < $n) {
            self::fallar('Ya no hay tantas unidades entregadas de ese producto: revisa si alguien deshizo la entrega.');
        }

        $item->update([
            'cantidad_entregada'   => (int) $item->cantidad_entregada - $n,
            'cantidad_en_garantia' => (int) $item->cantidad_en_garantia + $n,
        ]);

        $produccion = Produccion::where('orden_item_id', $item->id)->first();
        if ($produccion) {
            // Sus pasos de fabricación se quedan como están: completados, con
            // quién los hizo. Los del arreglo se agregan después.
            $produccion->update([
                'estado'         => 'pendiente',
                'fecha_real'     => null,
                'despachado_por' => null,
                'motivo_retraso' => $motivo,
            ]);
        } else {
            $produccion = Produccion::create([
                'orden_item_id'    => $item->id,
                'fecha_inicio'     => now()->toDateString(),
                'fecha_compromiso' => null,
                'estado'           => 'pendiente',
                'motivo_retraso'   => $motivo,
            ]);
        }

        $g->produccion_id = $produccion->id;
        $g->setRelation('item', $item);

        self::ponerOrdenAlDia($g->orden);
    }

    /**
     * Le arma a la pieza los pasos del arreglo (después de los que ya tenía)
     * con despacho al final, porque tiene que volver a salir por la puerta.
     * Si alguien ya le armó pasos a mano desde el tablero, no se duplican.
     */
    private static function armarPasos(Garantia $g, Usuario $quien): void
    {
        $produccion = Produccion::with('ordenItem.producto', 'ordenItem.orden')->find($g->produccion_id);
        if (! $produccion) return;

        $yaTienePasos = ProduccionPaso::where('produccion_id', $produccion->id)
            ->whereIn('estado', ['pendiente', 'en_proceso'])->exists();
        if ($yaTienePasos) return;

        $pasos = collect($g->procesos_reparacion ?? [])
            ->values()->map(fn ($clave, $i) => ['tipo_proceso' => $clave, 'orden' => $i + 1])->all();

        $linea  = TipoProceso::lineaDe((bool) ($produccion->ordenItem?->es_restauracion ?? false));
        $primer = ProduccionController::agregarPasos($produccion, $pasos, $linea, conDespacho: true, garantiaId: $g->id);

        $produccion->update([
            'estado' => $primer?->tipo_proceso === ProduccionPaso::DESPACHO ? 'pendiente_despachador' : 'en_proceso',
        ]);

        if ($primer) {
            ProduccionController::notificarTrabajadores(
                $primer->tipo_proceso, $primer->linea, $produccion->id,
                $g->orden_id, '🛡️ ' . self::nombre($produccion->ordenItem) . ' (garantía)',
            );
        }

        self::ponerOrdenAlDia($g->orden);

        try { event(new \App\Events\ProduccionActualizada($produccion->id, $g->orden_id, $produccion->estado)); } catch (\Throwable) {}
    }

    /** Solo procesos del taller que existan; despacho lo pone el sistema. */
    private static function procesosValidos(array $claves): array
    {
        $claves = array_values(array_unique(array_filter(array_map('strval', $claves))));
        if (! $claves) return [];

        $existen = TipoProceso::whereIn('clave', $claves)->pluck('clave')->all();

        return array_values(array_filter($claves,
            fn ($c) => $c !== ProduccionPaso::DESPACHO && in_array($c, $existen, true)));
    }

    /**
     * Agrega a la orden el renglón con el reemplazo y saca de la cuenta las
     * unidades dañadas.
     *
     * De inventario: se aparta en la tienda elegida, y solo si allá hay
     * libres de verdad (lo apartado para otras órdenes no cuenta). Si no hay
     * en ninguna, se puede mandar a fabricar: el renglón nace "para fabricar"
     * con su producción pendiente, como cualquier pedido.
     *
     * @param array{producto_id:int, variante_id:?int, combo_config_id:?int, tienda_id:?int, fabricar:bool} $r
     */
    private static function crearReemplazo(Garantia $g, array $r, float $precio, Usuario $quien): OrdenItem
    {
        $orden    = Orden::lockForUpdate()->findOrFail($g->orden_id);
        $viejo    = OrdenItem::lockForUpdate()->findOrFail($g->orden_item_id);
        $n        = (int) $g->cantidad;
        $producto = Producto::find($r['producto_id']);

        if (! $producto) self::fallar('Ese producto no existe.');

        $varianteId = $r['variante_id'] ? (int) $r['variante_id'] : null;
        $comboId    = $r['combo_config_id'] ? (int) $r['combo_config_id'] : null;
        if ($varianteId && ! ProductoVariante::whereKey($varianteId)->where('producto_id', $producto->id)->exists()) {
            self::fallar('Esa tela o variante no es de ese producto.');
        }
        if ($comboId && ! ProductoVarianteConfig::whereKey($comboId)->where('producto_id', $producto->id)->exists()) {
            self::fallar('Esa opción no es de ese producto.');
        }

        $fabricar = (bool) $r['fabricar'];
        $tiendaId = $fabricar ? null : (int) ($r['tienda_id'] ?? 0);
        if (! $fabricar) {
            if (! $tiendaId || ! Tienda::whereKey($tiendaId)->exists()) {
                self::fallar('Escoge de qué tienda sale el reemplazo, o márcalo para fabricar.');
            }
            $libre = self::stockLibre($producto->id, $varianteId, $comboId, $tiendaId);
            if ($libre < $n) {
                $tienda = Tienda::find($tiendaId)?->nombre ?? 'esa tienda';
                self::fallar("En {$tienda} no hay suficientes libres de \"{$producto->nombre}\": hay {$libre} y se necesitan {$n}. Escoge otra tienda o mándalo a fabricar.");
            }
        }

        $nuevo = OrdenItem::create([
            'orden_id'         => $orden->id,
            'producto_id'      => $producto->id,
            'variante_id'      => $varianteId,
            'combo_config_id'  => $comboId,
            'variante_detalle' => OrdenItem::detalleDeVariante(null, $comboId, $varianteId),
            'cantidad'         => $n,
            'precio_unitario'  => $precio,
            'es_personalizado' => $fabricar,
            'fabricar_pedido'  => $fabricar,
            'es_restauracion'  => false,
            'tienda_origen_id' => $fabricar ? null : ($tiendaId !== (int) $orden->tienda_id ? $tiendaId : null),
        ]);

        if ($fabricar) {
            Produccion::create([
                'orden_item_id'    => $nuevo->id,
                'fecha_inicio'     => now()->toDateString(),
                'fecha_compromiso' => null,
                'estado'           => 'pendiente',
                'motivo_retraso'   => 'Reposición por garantía: ' . $g->motivo,
            ]);
            // La tela se aparta sin frenar la garantía: si no alcanza, los
            // metros quedan en negativo y en Telas se ve que hay que comprar.
            ConsumoTelas::sincronizarItem($nuevo, $orden, false);
        } else {
            self::apartar($nuevo, $tiendaId, $quien, "Reposición por garantía — orden {$orden->referencia}");
        }

        // Lo dañado deja de cobrarse. Si era todo el renglón queda como
        // rastro (devuelto); si era parte, esas unidades salen de él. La pieza
        // dañada ya había salido del inventario al entregarla: no se toca.
        if ($n >= (int) $viejo->cantidad) {
            $viejo->update(['devuelto_en' => now('America/Bogota')->toDateString(), 'motivo_devolucion' => 'Garantía: ' . $g->motivo]);
        } else {
            $viejo->update([
                'cantidad'           => (int) $viejo->cantidad - $n,
                'cantidad_entregada' => max(0, (int) $viejo->cantidad_entregada - $n),
            ]);
        }

        $antes = (float) $orden->valor_total;
        $orden->recalcularTotal();
        if (abs((float) $orden->valor_total - $antes) >= 0.01) {
            // El valor cambió: la comisión lo sigue.
            ComisionController::sincronizarValorOrden($orden->fresh());
        }

        $g->orden_item_nuevo_id = $nuevo->id;
        $g->diferencia_valor    = round($n * $precio - $n * (float) $viejo->precio_unitario, 2);

        self::ponerOrdenAlDia($orden);

        return $nuevo;
    }

    /** Libres de verdad en esa tienda: lo disponible menos lo apartado. */
    public static function stockLibre(int $productoId, ?int $varianteId, ?int $comboId, int $tiendaId): int
    {
        $libre = fn ($fila) => $fila ? (int) $fila->cantidad_disponible - (int) $fila->cantidad_reservada : 0;

        $base = $libre(Inventario::where('producto_id', $productoId)->where('tienda_id', $tiendaId)->first());
        if ($varianteId && $comboId) {
            $fino = $libre(InventarioVarianteCombinacion::where('variante_id', $varianteId)
                ->where('config_id', $comboId)->where('tienda_id', $tiendaId)->first());
        } elseif ($varianteId) {
            $fino = $libre(InventarioVariante::where('variante_id', $varianteId)->where('tienda_id', $tiendaId)->first());
        } else {
            return $base;
        }

        // La variante es parte del stock base: tiene que alcanzar en los dos.
        return min($base, $fino);
    }

    private static function apartar(OrdenItem $item, int $tiendaId, Usuario $quien, string $motivo): void
    {
        $n = (int) $item->cantidad;

        if ($item->variante_id) {
            InventarioVariante::where('variante_id', $item->variante_id)->where('tienda_id', $tiendaId)
                ->increment('cantidad_reservada', $n);
            if ($item->combo_config_id) {
                InventarioVarianteCombinacion::where('variante_id', $item->variante_id)
                    ->where('config_id', $item->combo_config_id)->where('tienda_id', $tiendaId)
                    ->increment('cantidad_reservada', $n);
            }
        }
        Inventario::where('producto_id', $item->producto_id)->where('tienda_id', $tiendaId)
            ->increment('cantidad_reservada', $n);

        InventarioMovimiento::create([
            'producto_id' => $item->producto_id,
            'tienda_id'   => $tiendaId,
            'tipo'        => 'reserva',
            'cantidad'    => $n,
            'motivo'      => $motivo,
            'usuario_id'  => $quien->id,
        ]);

        try { event(new \App\Events\InventarioActualizado($tiendaId, (int) $item->producto_id, 'reserva')); } catch (\Throwable) {}
    }

    /**
     * La orden queda en el estado que le toca con lo que de verdad tiene
     * pendiente. Una orden entregada que se reactiva vuelve a "en producción"
     * (algo está en el taller) o "lista para entrega" (el reemplazo ya está
     * apartado); cuando todo vuelve a la casa, la entrega la cierra sola.
     */
    private static function ponerOrdenAlDia(Orden $orden): void
    {
        $orden->refresh()->load('items.produccion');

        if (in_array($orden->estado, ['cancelado', 'devuelto', 'borrador', 'cotizacion'], true)) return;

        if ($orden->estado !== 'entregado') {
            $nuevo = $orden->estadoTrasEntrega();
        } elseif ($orden->todoEntregado()) {
            return;
        } else {
            $enTaller = $orden->items->contains(fn (OrdenItem $i) =>
                $i->pendienteEntregar() > 0 && $i->pasaPorElTaller()
                && ($i->produccion === null || ! in_array($i->produccion->estado, ['listo', 'entregado'], true)));
            $nuevo = $enTaller ? 'en_produccion' : 'listo_entrega';
        }

        if ($nuevo === $orden->estado) return;

        $cambios = ['estado' => $nuevo];
        if ($nuevo === 'en_produccion') $cambios['listo_entrega_at'] = null;
        if ($nuevo === 'listo_entrega' && ! $orden->listo_entrega_at) $cambios['listo_entrega_at'] = now();
        $orden->update($cambios);

        try {
            event(new \App\Events\OrdenActualizada($orden->id, (int) $orden->tienda_id, $nuevo, (string) ($orden->cliente?->nombre ?? '')));
        } catch (\Throwable) {}
    }

    // ── Avisos y rastro ──────────────────────────────────────────────────────

    /**
     * A quien decide, urgente: hay un cliente esperando y un plazo legal
     * corriendo (15 días hábiles).
     */
    private static function avisarReporte(Garantia $g): void
    {
        $destinatarios = Usuario::where('activo', true)
            ->where(fn ($q) => $q->where('gestiona_produccion', true)->orWhere('rol', 'supervisor'))
            ->get();

        $vencida = $g->dentroDeGarantia() === false ? ' (la garantía ya venció)' : '';
        foreach ($destinatarios->unique('id') as $u) {
            NotificacionService::crear(
                'garantia',
                'Garantía por decidir',
                self::nombre($g->item) . ' de ' . ($g->orden?->cliente?->nombre ?? 'el cliente')
                    . ": {$g->motivo}{$vencida}. Responder antes del {$g->responder_antes_de->format('d/m/Y')}.",
                ['orden_id' => $g->orden_id, 'garantia_id' => $g->id],
                $u->id,
                urgente: true,
            );
        }
    }

    /** Al vendedor, que es a quien el cliente le va a preguntar; y a quien va a la casa. */
    private static function avisarDecision(Garantia $g, Usuario $quien): void
    {
        $texto = [
            'taller'       => 'se arregla en el taller',
            'domicilio'    => 'se arregla en la casa del cliente',
            'cambio_mismo' => 'se cambia por otro igual',
            'cambio_otro'  => 'se cambia por otro producto',
            'reembolso'    => 'se le devuelve la plata cuando devuelva el producto',
            'no_procede'   => 'no procede',
        ][$g->decision] ?? $g->decision;

        $vendedorId = $g->orden?->vendedor_id;
        if ($vendedorId && (int) $vendedorId !== (int) $quien->id) {
            NotificacionService::crear(
                'garantia',
                'Garantía decidida',
                self::nombre($g->item) . ' de ' . ($g->orden?->cliente?->nombre ?? 'el cliente') . ": {$texto}.",
                ['orden_id' => $g->orden_id, 'garantia_id' => $g->id],
                (int) $vendedorId,
            );
        }

        if ($g->decision === 'domicilio' && $g->visita_por_id && (int) $g->visita_por_id !== (int) $quien->id) {
            NotificacionService::crear(
                'garantia',
                'Te toca una visita de garantía',
                self::nombre($g->item) . ' de ' . ($g->orden?->cliente?->nombre ?? 'el cliente')
                    . ' el ' . $g->visita_fecha->format('d/m/Y') . ": {$g->motivo}",
                ['orden_id' => $g->orden_id, 'garantia_id' => $g->id],
                (int) $g->visita_por_id,
            );
        }
    }

    private static function anotar(Orden $orden, Usuario $quien, string $mensaje, ?string $foto = null): void
    {
        OrdenMensaje::create([
            'orden_id'   => $orden->id,
            'usuario_id' => $quien->id,
            'mensaje'    => $mensaje,
            'imagen_url' => $foto,
        ]);
    }

    private static function notas(Garantia $g): string
    {
        return $g->notas_decision ? " {$g->notas_decision}" : '';
    }

    public static function nombre(?OrdenItem $item): string
    {
        if (! $item) return 'Un producto';

        return $item->nombre_custom ?: ($item->producto?->nombre ?? 'Producto');
    }

    private static function nombreTienda(OrdenItem $item, Orden $orden): string
    {
        return Tienda::find($item->tienda_origen_id ?? $orden->tienda_id)?->nombre ?? 'la tienda';
    }

    private static function fallar(string $mensaje): never
    {
        throw new HttpResponseException(response()->json(['message' => $mensaje], 422));
    }

    private static ?bool $hayTabla = null;

    /** Las pruebas montan el esquema a mano y muchas no tienen esta tabla. */
    private static function hayTabla(): bool
    {
        return self::$hayTabla ??= Schema::hasTable('garantias');
    }

    /** Ver CachesDePeticion. */
    public static function olvidarCache(): void
    {
        self::$hayTabla = null;
    }
}
