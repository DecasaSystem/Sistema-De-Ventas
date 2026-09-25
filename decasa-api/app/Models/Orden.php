<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Orden extends Model
{
    protected $table = 'ordenes';

    /**
     * Estados que NO son una venta: la cotización es una propuesta que el
     * cliente todavía no acepta, y el borrador es una venta sin cerrar.
     * Excluir en estadísticas, reportes, comisiones y cartera.
     */
    public const ESTADOS_NO_COMERCIALES = ['cotizacion', 'borrador'];

    /**
     * Estados en los que una orden NO tiene stock apartado.
     *
     * Los tres primeros porque ya se soltó —se entregó, se canceló o se
     * devolvió—; los otros dos porque todavía no se ha apartado nada: un
     * borrador y una cotización solo tocan inventario al confirmarse
     * (`OrdenController::completarBorrador`, `CotizacionController::convertir`).
     *
     * Vive aquí porque la misma lista la necesitan tres sitios que tienen que
     * decir lo mismo: quién lista las reservas, quién audita los descuadres y
     * quién suelta la reserva al cancelar. Cuando cada uno llevaba su propia
     * copia se desincronizaban, y de ahí salían los apartados fantasma que
     * nadie lograba explicar.
     */
    public const ESTADOS_SIN_RESERVA = [
        'entregado', 'cancelado', 'devuelto', 'cotizacion', 'borrador',
    ];

    protected $fillable = [
        'cliente_id',
        'vendedor_id',
        'tienda_id',
        'tienda_abonada_id',
        'canal',
        'entrega_inmediata',
        'tipo',
        'estado',
        'confirmada_en',
        'numero_orden',
        'serie',
        'serie_numero',
        'motivo_serie',
        'grupo_secuencia',
        // La referencia que tenía una orden anulada cuyo número se soltó para
        // correr las siguientes (ver NumeracionOrdenes::liberarYCorrer()).
        'numero_anulado',
        'cotizacion_estado',
        'cotizacion_numero',
        'cotizacion_valida_hasta',
        'motivo_perdida',
        'contacto_nombre',
        'contacto_telefono',
        'contacto_email',
        'valor_total',
        'descuento_total',
        'descuento_condicionado',
        'descuento_condicionado_pct',
        'descuento_condicionado_revertido_at',
        'anticipo_pct',
        'notas',
        'fecha_sugerida_vendedor',
        'es_compartida',
        // FV2 cuya comisión no se divide por 1,19 (ver la migración fv2_sin_descontar_iva).
        'sin_descontar_iva',
        'covendedor_id',
        'factura_foto_url',
        // Todas las fotos del comprobante; factura_foto_url lleva la primera.
        'factura_fotos',
        'firma_url',
        'anexo_foto_url',
        'direccion_envio',
        'ciudad_envio',
        'departamento_envio',
        'listo_entrega_at',
        'tienda_vendedor_id',
        'clave_envio',
    ];

    /**
     * De qué tienda era el vendedor cuando vendió.
     *
     * Una venta digital cuenta para la tienda del vendedor, no para la de la
     * orden (ver ComisionController::tiendaParaComision). Se miraba su tienda
     * de HOY, y esa cuenta se rehace con cada pago, cambio de canal o
     * "Recalcular": Manuela vendió el 31 de agosto en Tienda Virtual, pasó al
     * Norte el 1 de septiembre, y su venta de agosto terminó sumándole al
     * Norte. Se guarda al crear la orden y cuando cambia el vendedor.
     */
    protected static function booted(): void
    {
        static::creating(function (Orden $o) {
            if ($o->vendedor_id && ! $o->tienda_vendedor_id && self::guardaTiendaVendedor()) {
                $o->tienda_vendedor_id = Usuario::where('id', $o->vendedor_id)->value('tienda_default_id');
            }
        });

        static::updating(function (Orden $o) {
            if ($o->isDirty('vendedor_id') && ! $o->isDirty('tienda_vendedor_id') && self::guardaTiendaVendedor()) {
                $o->tienda_vendedor_id = $o->vendedor_id
                    ? Usuario::where('id', $o->vendedor_id)->value('tienda_default_id')
                    : null;
            }
        });
    }

    /**
     * Lo que va en `Orden::create` para la clave del envío (ver
     * OrdenController::ordenYaEnviada): nada si no vino o si la columna no
     * existe. Si la migración no hubiera corrido, crear órdenes tiene que
     * seguir funcionando —solo sin esta protección—.
     */
    public static function conClaveEnvio(?string $clave): array
    {
        return $clave && self::tieneClaveEnvio() ? ['clave_envio' => $clave] : [];
    }

    public static function tieneClaveEnvio(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasColumn('ordenes', 'clave_envio');
    }

    /**
     * Si la columna existe: en las pruebas con esquema propio puede no estar.
     * Sin caché a propósito: las pruebas cambian de esquema en el mismo
     * proceso, y es una consulta solo al crear una orden o cambiarle vendedor.
     */
    private static function guardaTiendaVendedor(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasColumn('ordenes', 'tienda_vendedor_id');
    }

    protected function casts(): array
    {
        return [
            'valor_total'      => 'decimal:2',
            'descuento_total'  => 'decimal:2',
            'descuento_condicionado' => 'decimal:2',
            // Los porcentajes viajan como número, no como "50.00".
            //
            // `decimal:2` los serializa a texto con dos decimales, y la
            // pantalla los pintaba tal cual: el campo "% de anticipo sugerido"
            // decía 50,00 y el aviso del descuento, "5,00%". Nadie escribe
            // medio punto porcentual, así que esos dos ceros solo estorban al
            // leer. La columna sigue siendo decimal en la base: esto es cómo
            // sale, no cómo se guarda.
            'descuento_condicionado_pct' => 'float',
            'descuento_condicionado_revertido_at' => 'datetime',
            'anticipo_pct'     => 'float',
            'es_compartida'    => 'boolean',
            'sin_descontar_iva' => 'boolean',
            'entrega_inmediata' => 'boolean',
            'listo_entrega_at' => 'datetime',
            'factura_fotos'    => 'array',
            'confirmada_en'    => 'datetime',
            'cotizacion_valida_hasta' => 'date',
            'fecha_sugerida_vendedor' => 'date',
        ];
    }

    protected $appends = ['esta_vencida', 'cotizacion_ref', 'contacto_display', 'referencia'];

    // ── Descuentos ───────────────────────────────────────────────────────────

    /**
     * Subtotal antes de cualquier descuento. El monto es la fuente de verdad,
     * así que el subtotal se reconstruye sumándolos de vuelta.
     */
    /**
     * Cuándo se entrega la orden completa: la fecha más lejana de sus ítems,
     * porque el cliente recibe todo cuando esté listo el último.
     *
     * Null si todavía falta asignarle fecha a alguno — media orden con fecha
     * no es una fecha de entrega, y decir la del primero prometería algo que
     * no se va a cumplir.
     *
     * Requiere `items` cargada.
     */
    public function fechaEntregaEstimada(): ?\Carbon\Carbon
    {
        // Solo lo que el cliente va a recibir: lo que devolvió para cambiarlo
        // ya no se entrega, y mirarlo dejaría la orden sin fecha para siempre.
        $items = $this->items->filter->estaVivo();

        if ($items->isEmpty() || $items->contains(fn ($i) => empty($i->fecha_entrega_prom))) {
            return null;
        }

        return $items
            ->map(fn ($i) => \Carbon\Carbon::parse($i->fecha_entrega_prom))
            ->sortDesc()
            ->first();
    }

    public function subtotalBruto(): float
    {
        return (float) $this->valor_total
            + (float) $this->descuento_total
            + (float) $this->descuento_condicionado;
    }

    /**
     * Porcentaje que representa el descuento comercial. Es informativo: el monto
     * manda, y recalcular desde este % daría una cifra ligeramente distinta.
     */
    public function getDescuentoPctAttribute(): float
    {
        $base = $this->subtotalBruto();
        return $base > 0 ? round((float) $this->descuento_total / $base * 100, 1) : 0.0;
    }

    // ── Descuento condicionado al medio de pago ──────────────────────────────

    /** Medios de pago que conservan el descuento. Tarjeta y "otro" no lo dan. */
    public const METODOS_CON_DESCUENTO = ['efectivo', 'transferencia'];

    /**
     * Medios de pago que cobran comisión de franquicia (el 5,5%): la tarjeta
     * y Addi. De esa plata la empresa recibe menos, y nadie comisiona sobre
     * lo que se queda el intermediario.
     */
    public const METODOS_CON_FRANQUICIA = ['tarjeta', 'addi'];

    /**
     * ¿La orden todavía tiene descuento condicionado que se pueda perder?
     */
    public function tieneDescuentoCondicionadoVivo(): bool
    {
        return (float) $this->descuento_condicionado > 0
            && $this->descuento_condicionado_revertido_at === null;
    }

    /**
     * Un medio de pago hace perder el descuento si no es efectivo ni transferencia.
     */
    public static function metodoPierdeDescuento(?string $metodo): bool
    {
        return $metodo !== null && ! in_array($metodo, self::METODOS_CON_DESCUENTO, true);
    }

    /**
     * Cuánto costaría la orden si se pierde el descuento condicionado.
     */
    public function valorSinDescuentoCondicionado(): float
    {
        return (float) $this->valor_total + (float) $this->descuento_condicionado;
    }

    // ── Series especiales ────────────────────────────────────────────────────

    /** Serie de órdenes con descuento especial. */
    public const SERIE_FV2 = 'FV2';

    /**
     * Las restauraciones llevan su propia numeración (R-1092) y no gastan
     * consecutivo de venta: no son una venta de mueble, son un trabajo sobre
     * un mueble del cliente.
     *
     * Solo las órdenes que son ÍNTEGRAMENTE restauración. Si además se le
     * vende algo, es una venta con un arreglo incluido y lleva número normal.
     */
    public const SERIE_RESTAURACION = 'R';

    /**
     * No es una serie real: nunca se guarda en `serie`. Es el valor que se le
     * manda a NumeracionOrdenes::convertir() para decir "sácala de FV2/R y
     * dale número de venta normal de su tienda" — el otro sentido de la
     * corrección, para cuando la equivocación fue marcarla como serie
     * especial de más.
     */
    public const SERIE_NORMAL = 'NORMAL';

    /**
     * Cómo se nombra esta orden donde sea que se muestre: "FV2-3" si es de
     * serie especial, "#4261" si es una orden normal. Evita que las FV2
     * aparezcan como "#" vacío por no tener numero_orden.
     */
    public function getReferenciaAttribute(): string
    {
        // Se anuló y su número se le dio a la siguiente: ya no es de nadie,
        // pero hay que seguir sabiendo cuál era para encontrarla.
        if ($this->numero_anulado) {
            return 'Anulada (era ' . $this->numero_anulado . ')';
        }
        if ($this->serie && $this->serie_numero) {
            return $this->serie . '-' . $this->serie_numero;
        }
        if ($this->cotizacion_numero && $this->estado === 'cotizacion') {
            return 'COT-' . $this->cotizacion_numero;
        }
        // Sin consecutivo todavía: el número se gasta cuando la venta es de
        // verdad. Mostrar el id de la tabla no le dice nada a nadie.
        if ($this->numero_orden === null) {
            if ($this->estado === 'borrador')             return 'Borrador';
            // Esperando el precio del taller: si el cliente no acepta, no se
            // quema ningún consecutivo.
            if ($this->estado === 'pendiente_cotizacion') return 'Sin número — esperando precio';
        }

        return '#' . ($this->numero_orden ?? $this->id);
    }

    public function getEsDescuentoEspecialAttribute(): bool
    {
        return $this->serie === self::SERIE_FV2;
    }

    // ── Cotizaciones ─────────────────────────────────────────────────────────

    /**
     * Órdenes que cuentan como venta. Excluye cotizaciones (el cliente todavía
     * no compra) y borradores (falta cerrar el papeleo). Usar en estadísticas,
     * reportes, comisiones y cartera.
     */
    public function scopeComerciales($query)
    {
        return $query->whereNotIn('estado', ['cotizacion', 'borrador']);
    }

    public function scopeCotizaciones($query)
    {
        return $query->where('estado', 'cotizacion');
    }

    /**
     * Vencida se calcula, no se guarda: así una cotización nunca aparece vigente
     * por error si el job diario no corrió.
     */
    public function getEstaVencidaAttribute(): bool
    {
        if ($this->estado !== 'cotizacion' || ! $this->cotizacion_valida_hasta) {
            return false;
        }
        if (in_array($this->cotizacion_estado, ['convertida', 'perdida'], true)) {
            return false;
        }

        return $this->cotizacion_valida_hasta->endOfDay()->isPast();
    }

    public function getCotizacionRefAttribute(): ?string
    {
        return $this->cotizacion_numero ? 'COT-' . $this->cotizacion_numero : null;
    }

    /**
     * A quién va dirigida: cliente formal si existe, si no el contacto suelto.
     */
    public function getContactoDisplayAttribute(): string
    {
        // Solo lee la relación si ya viene cargada: este accesor está en
        // $appends y se evalúa en cada orden serializada, así que tocar
        // cliente() aquí provocaría una consulta por fila en los listados.
        $nombreCliente = $this->relationLoaded('cliente') ? $this->cliente?->nombre : null;

        return $nombreCliente
            ?? $this->contacto_nombre
            ?? 'Sin datos de contacto';
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function vendedor()
    {
        return $this->belongsTo(Usuario::class, 'vendedor_id');
    }

    public function covendedor()
    {
        return $this->belongsTo(Usuario::class, 'covendedor_id');
    }

    public function tienda()
    {
        return $this->belongsTo(Tienda::class, 'tienda_id');
    }

    /**
     * De qué tipo es una orden, en SQL, para partir la plata en los reportes.
     *
     * Tres cajones que no se solapan, para que la suma de los tres dé
     * exactamente el total y nadie tenga que cuadrar diferencias a mano:
     *
     *   fv2          → la serie con descuento especial. Manda sobre lo demás,
     *                  igual que al numerarla: si lleva FV2 impreso, es FV2.
     *   restauracion → el mueble es del cliente. Se reconoce por la serie R o
     *                  porque TODOS sus ítems son restauración; una que mezcla
     *                  un comedor con una restauración es una venta, que es
     *                  como se numera y como se comisiona.
     *   venta        → todo lo demás.
     *
     * Vive aquí y no repetida en cada consulta porque el resumen, las tiendas,
     * los vendedores y el Excel tienen que partir la plata igual: si una lo
     * hiciera distinto, los reportes dejarían de cuadrar entre ellos.
     */
    public static function sqlTipo(string $alias = 'o'): string
    {
        return "
            CASE
                WHEN {$alias}.serie = '" . self::SERIE_FV2 . "' THEN 'fv2'
                WHEN {$alias}.serie = '" . self::SERIE_RESTAURACION . "' THEN 'restauracion'
                WHEN {$alias}.id IN (
                    SELECT oi.orden_id FROM orden_items oi
                    GROUP BY oi.orden_id HAVING COUNT(*) = SUM(oi.es_restauracion)
                ) THEN 'restauracion'
                ELSE 'venta'
            END
        ";
    }

    /**
     * Las tres columnas de plata por tipo, para meter en un SELECT.
     *
     * @param string $monto  qué se suma: el pago cobrado o el valor de la orden
     */
    public static function selectMontosPorTipo(string $monto, string $alias = 'o'): string
    {
        $tipo = self::sqlTipo($alias);

        return "
            COALESCE(SUM(CASE WHEN ($tipo) = 'venta'        THEN $monto ELSE 0 END), 0) AS monto_venta,
            COALESCE(SUM(CASE WHEN ($tipo) = 'restauracion' THEN $monto ELSE 0 END), 0) AS monto_restauracion,
            COALESCE(SUM(CASE WHEN ($tipo) = 'fv2'          THEN $monto ELSE 0 END), 0) AS monto_fv2
        ";
    }

    /** La tienda que ayudo con el contacto y se lleva la mitad de la venta. */
    public function tiendaAbonada()
    {
        return $this->belongsTo(Tienda::class, 'tienda_abonada_id');
    }

    /**
     * Las órdenes que esta persona puede ver.
     *
     * Quien no está limitado a lo suyo —un supervisor— las ve todas. Al
     * vendedor le salen las que vendió y, además, aquellas en las que le toca
     * parte:
     *
     *  - Las que le compartieron como covendedor. Comisiona la mitad de esa
     *    venta, así que no puede ser invisible para él.
     *  - Las que un independiente le abonó a SU tienda. Ahí la mitad de la
     *    comisión se reparte entre todos los que trabajan en la tienda
     *    (ver ComisionController::sincronizarAbonoAlmacen), así que la orden
     *    es tan suya como del que la vendió. El borrador no cuenta: todavía
     *    se está armando y no se ha compartido con nadie.
     *  - Si lleva facturación, las entregadas de su tienda, que es lo que
     *    tiene que facturar.
     *
     * La regla vive aquí y no en cada controlador porque estaba copiada en
     * tres sitios: la lista, el detalle y el módulo de restauraciones. Ahora
     * los tres responden lo mismo, que es lo mínimo para que una orden no
     * aparezca en una pantalla y dé "no autorizado" en la siguiente.
     */
    public function scopeVisiblesPara($query, Usuario $usuario)
    {
        if (! $usuario->soloVeSusOrdenes()) {
            return $query;
        }

        // Un facturador factura lo de TODAS las tiendas: el sistema ya le
        // avisa de cada abono que entra en cualquiera de ellas y le deja
        // tomar su facturación (ver PagoController::tomarFacturacion, que no
        // mira tienda ni estado). Lo que faltaba era poder abrir la orden
        // para ver qué está facturando: le llegaba el aviso, entraba, y le
        // salía "No autorizado". Ver no es tocar — editar y cobrar siguen
        // con sus reglas de siempre.
        if ($usuario->facturacion) {
            return $query;
        }

        return $query->where(function ($q) use ($usuario) {
            $q->where('vendedor_id', $usuario->id)
              ->orWhere('covendedor_id', $usuario->id);

            if (! $usuario->tienda_default_id) {
                return;
            }

            $q->orWhere(fn ($q2) => $q2
                ->where('tienda_abonada_id', $usuario->tienda_default_id)
                ->where('estado', '!=', 'borrador'));
        });
    }

    /**
     * ¿Puede cobrarle a esta orden: registrar un abono, verificarlo o
     * corregirle el medio de pago?
     *
     * Hoy es la misma respuesta que "¿puede verla?", y a propósito: si la
     * venta se comparte con una tienda, el cliente puede llegar a esa tienda
     * a abonar, y quien lo atienda tiene que poder recibirle la plata. Va
     * aparte de laPuedeVer() porque es un permiso sobre dinero: si algún día
     * se separan, se cambia aquí y no hay que buscarlo por los controladores.
     */
    public function laPuedeCobrar(Usuario $usuario): bool
    {
        if (! $usuario->soloVeSusOrdenes())                    return true;
        if ((int) $this->vendedor_id   === (int) $usuario->id) return true;
        if ((int) $this->covendedor_id === (int) $usuario->id) return true;

        $tienda = (int) $usuario->tienda_default_id;
        if (! $tienda) return false;

        if ((int) $this->tienda_abonada_id === $tienda && $this->estado !== 'borrador') {
            return true;
        }

        // Al facturador se le abrió VER todas las órdenes, pero recibir plata
        // es otra cosa: aquí sigue la regla de siempre —las entregadas de su
        // tienda, para cuadrar caja—. Si algún día tiene que poder cobrar en
        // cualquier tienda, se decide y se cambia aquí; no se hereda de un
        // permiso de lectura sin que nadie lo haya pedido.
        return (bool) $usuario->facturacion
            && (int) $this->tienda_id === $tienda
            && $this->estado === 'entregado';
    }

    /**
     * ¿Puede editarla, completarla o mover su cotización?
     *
     * Una venta compartida es de los dos: del vendedor y de su covendedor, o
     * de la tienda a la que se le abona la mitad. Si el cliente vuelve donde
     * el otro a cambiar un producto o a corregir la dirección, ese otro tiene
     * que poder hacerlo, no mandarlo a buscar a quien hizo la venta. Antes
     * solo el vendedor principal pasaba, aunque la venta le saliera en la
     * lista al covendedor.
     *
     * Es "ver" sin el caso de facturación: quien ve las entregadas de su
     * tienda para cuadrar caja no es dueño de la venta y no la edita.
     */
    public function laPuedeEditar(Usuario $usuario): bool
    {
        if (! $usuario->soloVeSusOrdenes())                    return true;
        if ((int) $this->vendedor_id   === (int) $usuario->id) return true;
        if ((int) $this->covendedor_id === (int) $usuario->id) return true;

        $tienda = (int) $usuario->tienda_default_id;

        return $tienda > 0
            && (int) $this->tienda_abonada_id === $tienda
            && $this->estado !== 'borrador';
    }

    /**
     * La misma regla que `scopeVisiblesPara`, para una orden concreta.
     *
     * Un facturador las ve todas: tiene que revisar la información completa
     * de lo que factura, venga de la tienda que venga. Es SOLO leer — lo que
     * puede tocar lo dicen `laPuedeEditar` y `laPuedeCobrar`, cada una con su
     * regla, que no cambian.
     */
    public function laPuedeVer(Usuario $usuario): bool
    {
        if (! $usuario->soloVeSusOrdenes()) return true;
        if ((bool) $usuario->facturacion)   return true;

        return $this->laPuedeCobrar($usuario);
    }

    /**
     * ¿Este usuario puede hacerle una ENTREGA DIRECTA a esta orden?
     *
     * La entrega directa es el camino corto —sin ruta ni conductor— para
     * cuando los conductores no están usando el programa. Exige el permiso
     * `acceso_entregas` y que haya ALGO que entregar hoy: un producto de
     * catálogo apartado, o uno fabricado que el taller ya dio por listo. Ya
     * no hace falta que la orden entera esté en `listo_entrega`: el reloj
     * se entrega el día de la venta y el mueble cuando salga.
     *
     *  - Supervisor: cualquier orden dentro de su alcance normal.
     *  - Vendedor: solo si es suya (la vendió o es covendedor) Y salió de su
     *    tienda — no puede despachar mercancía de otra sede.
     *  - Y en los dos casos: que nadie más la esté despachando ya.
     */
    public function laPuedeEntregarDirecto(Usuario $usuario): bool
    {
        if (! $usuario->acceso_entregas)      return false;
        if (! $this->tieneAlgoParaEntregar()) return false;

        if ($usuario->rol === 'supervisor') {
            if (! $this->laPuedeVer($usuario)) return false;
        } else {
            $esSuya = (int) $this->vendedor_id   === (int) $usuario->id
                   || (int) $this->covendedor_id === (int) $usuario->id;

            if (! $esSuya) return false;
            if ((int) $this->tienda_id !== (int) $usuario->tienda_default_id) return false;
        }

        return ! $this->tieneDespachoActivo();
    }

    /** Los estados en los que una orden es una venta viva con algo por entregar. */
    public const ESTADOS_ENTREGABLES = ['pendiente_anticipo', 'en_produccion', 'listo_entrega'];

    /** ¿Hay al menos un producto que se pueda entregar hoy? */
    public function tieneAlgoParaEntregar(): bool
    {
        if (! in_array($this->estado, self::ESTADOS_ENTREGABLES, true)) return false;

        return $this->itemsEntregables()->isNotEmpty();
    }

    /**
     * Los productos que se pueden entregar hoy, con cuántas unidades faltan.
     *
     * @return \Illuminate\Support\Collection<int, OrdenItem>
     */
    public function itemsEntregables()
    {
        $this->loadMissing('items.produccion');

        return $this->items->filter(fn ($i) => $i->estaListoParaEntregar())->values();
    }

    /**
     * Cómo va la entrega: cuántas unidades se han entregado de las que van.
     *
     * Es lo que pinta "entrega parcial 1/2" en la orden y en las listas. No
     * es un estado nuevo: la orden se queda en el suyo (en producción, lista)
     * y esto la acompaña.
     *
     * @return array{total:int, entregados:int, pendientes:int, parcial:bool, completa:bool}
     */
    public function resumenEntrega(): array
    {
        $vivos = $this->items->filter->estaVivo();
        $total = (int) $vivos->sum('cantidad');
        $hecho = (int) $vivos->sum('cantidad_entregada');

        return [
            'total'      => $total,
            'entregados' => min($hecho, $total),
            'pendientes' => max(0, $total - $hecho),
            'parcial'    => $hecho > 0 && $hecho < $total,
            'completa'   => $total > 0 && $hecho >= $total,
        ];
    }

    /**
     * Vuelve a sacar el total de la orden a partir de lo que sigue vivo.
     *
     * Lo devuelto no se cobra, así que no puede seguir sumando. Los descuentos
     * se topan contra el subtotal nuevo: si el descuento era mayor que lo que
     * quedó, dejarlo tal cual daría un total negativo. Lo ya pagado no se
     * toca: queda a favor del cliente contra el total nuevo.
     */
    public function recalcularTotal(): void
    {
        $this->load('items');

        $subtotal = $this->items->filter->estaVivo()
            ->sum(fn ($i) => $i->cantidad * $i->precio_unitario);

        $descuento    = min((float) $this->descuento_total, $subtotal);
        $baseCond     = max(0, $subtotal - $descuento);
        $condicionado = min((float) $this->descuento_condicionado, $baseCond);

        $this->update([
            'descuento_total'        => $descuento,
            'descuento_condicionado' => $condicionado,
            'valor_total'            => $baseCond - $condicionado,
        ]);
    }

    /** ¿Ya recibió el cliente todo lo que compró? */
    public function todoEntregado(): bool
    {
        return $this->resumenEntrega()['completa'];
    }

    /**
     * En qué estado queda la orden después de entregar parte (o todo).
     *
     *  - Todo entregado: `entregado`.
     *  - Algo volvió y espera decisión: `devuelto`.
     *  - Queda algo por fabricar: `en_produccion`.
     *  - Queda algo ya listo o de catálogo: `listo_entrega` si la orden ya
     *    había llegado ahí (o iba en camión); si no, se queda como estaba —el
     *    supervisor la pone lista cuando corresponda, como siempre—.
     */
    public function estadoTrasEntrega(bool $hayDevolucionPendiente = false): string
    {
        if ($this->todoEntregado()) return 'entregado';
        if ($hayDevolucionPendiente) return 'devuelto';

        $this->loadMissing('items.produccion');
        $pendientes = $this->items->filter(fn ($i) => $i->pendienteEntregar() > 0);

        $hayEnTaller = $pendientes->contains(fn ($i) =>
            $i->vaAlTaller()
            && ($i->produccion === null || ! in_array($i->produccion->estado, ['listo', 'entregado'], true))
        );
        if ($hayEnTaller) return 'en_produccion';

        if (in_array($this->estado, ['listo_entrega', 'en_camino', 'devuelto'], true)) return 'listo_entrega';

        return $this->estado;
    }

    /**
     * ¿Ya la está despachando alguien más? Un conductor que la lleva en su ruta,
     * o una entrega directa que otro abrió y todavía no termina.
     *
     * Solo cuentan los despachos vivos. Los que ya se cerraron —una ruta que se
     * completó sin entregar esta orden, o una entrega que después se revirtió—
     * dejan su fila ahí para siempre, y esa fila vieja no puede seguir
     * bloqueando la orden: por eso se mira el estado del despacho y no la sola
     * existencia del item.
     */
    public function tieneDespachoActivo(): bool
    {
        return DespachoItem::where('orden_id', $this->id)
            ->whereHas('despacho', fn ($q) => $q->whereIn('estado', ['borrador', 'asignado', 'en_ruta']))
            ->exists();
    }

    public function items()
    {
        return $this->hasMany(OrdenItem::class, 'orden_id');
    }

    public function pagos()
    {
        return $this->hasMany(Pago::class, 'orden_id');
    }

    /**
     * Lo que lleva pagado el cliente.
     *
     * Si los pagos ya vienen cargados se suman en memoria. Preguntarlo otra
     * vez a la base es un viaje por cada llamada, y esto se llama dos veces
     * seguidas en el detalle de la orden —una por el total y otra por el
     * saldo— y una vez por orden en las listas que ya las traen con sus pagos.
     *
     * OJO al registrar un pago: quien haya cargado la relación antes se queda
     * con la foto vieja y acá saldría un total de menos. Después de crear o
     * corregir un pago hay que pasar por `fresh()` —o volver a cargar `pagos`—
     * antes de preguntar. Es lo que hacen hoy los dos caminos de cobro, el de
     * PagoController y el del conductor en DespachoController.
     */
    public function totalPagado(): float
    {
        if ($this->relationLoaded('pagos')) {
            return (float) $this->pagos->sum('monto');
        }

        return (float) $this->pagos()->sum('monto');
    }

    /**
     * Cuánto de esta orden entró por datáfono.
     *
     * Se usa para la comisión: de esa plata la empresa no recibe el 5,5% que se
     * queda la franquicia, así que el vendedor no comisiona sobre ella. Se mira
     * pago por pago y no "si tocó la tarjeta", porque en un pago mixto sólo el
     * pedazo que pasó por datáfono tiene ese costo.
     */
    public function pagadoConTarjeta(): float
    {
        // Igual que totalPagado(): con los pagos en memoria no hace falta
        // volver a preguntar. En el cálculo de comisiones esto se llama por
        // cada orden del mes.
        // Addi cuenta igual que la tarjeta: también se queda el 5,5%.
        if ($this->relationLoaded('pagos')) {
            return (float) $this->pagos->whereIn('metodo', self::METODOS_CON_FRANQUICIA)->sum('monto');
        }

        return (float) $this->pagos()->whereIn('metodo', self::METODOS_CON_FRANQUICIA)->sum('monto');
    }

    public function saldoPendiente(): float
    {
        return (float) $this->valor_total - $this->totalPagado();
    }

    public function despachoItem()
    {
        return $this->hasOne(DespachoItem::class, 'orden_id');
    }

    /** Todas las entregas que ha tenido: con parciales puede haber varias. */
    public function entregas()
    {
        return $this->hasMany(DespachoItem::class, 'orden_id');
    }

    public function ediciones()
    {
        return $this->hasMany(OrdenEdicion::class, 'orden_id')->orderByDesc('created_at');
    }
}
