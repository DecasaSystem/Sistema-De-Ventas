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

    protected $fillable = [
        'cliente_id',
        'vendedor_id',
        'tienda_id',
        'tienda_abonada_id',
        'canal',
        'tipo',
        'estado',
        'confirmada_en',
        'numero_orden',
        'serie',
        'serie_numero',
        'motivo_serie',
        'grupo_secuencia',
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
        'covendedor_id',
        'factura_foto_url',
        'firma_url',
        'anexo_foto_url',
        'direccion_envio',
        'ciudad_envio',
        'departamento_envio',
        'listo_entrega_at',
    ];

    protected function casts(): array
    {
        return [
            'valor_total'      => 'decimal:2',
            'descuento_total'  => 'decimal:2',
            'descuento_condicionado' => 'decimal:2',
            'descuento_condicionado_pct' => 'decimal:2',
            'descuento_condicionado_revertido_at' => 'datetime',
            'anticipo_pct'     => 'decimal:2',
            'es_compartida'    => 'boolean',
            'listo_entrega_at' => 'datetime',
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
        $items = $this->items;

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
     * Cómo se nombra esta orden donde sea que se muestre: "FV2-3" si es de
     * serie especial, "#4261" si es una orden normal. Evita que las FV2
     * aparezcan como "#" vacío por no tener numero_orden.
     */
    public function getReferenciaAttribute(): string
    {
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

    /** La tienda que ayudo con el contacto y se lleva la mitad de la venta. */
    public function tiendaAbonada()
    {
        return $this->belongsTo(Tienda::class, 'tienda_abonada_id');
    }

    public function items()
    {
        return $this->hasMany(OrdenItem::class, 'orden_id');
    }

    public function pagos()
    {
        return $this->hasMany(Pago::class, 'orden_id');
    }

    public function totalPagado(): float
    {
        return (float) $this->pagos()->sum('monto');
    }

    public function saldoPendiente(): float
    {
        return (float) $this->valor_total - $this->totalPagado();
    }

    public function despachoItem()
    {
        return $this->hasOne(DespachoItem::class, 'orden_id');
    }

    public function ediciones()
    {
        return $this->hasMany(OrdenEdicion::class, 'orden_id')->orderByDesc('created_at');
    }
}
