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
        'canal',
        'tipo',
        'estado',
        'numero_orden',
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
        'anticipo_pct',
        'notas',
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
            'anticipo_pct'     => 'decimal:2',
            'es_compartida'    => 'boolean',
            'listo_entrega_at' => 'datetime',
            'cotizacion_valida_hasta' => 'date',
        ];
    }

    protected $appends = ['esta_vencida', 'cotizacion_ref', 'contacto_display'];

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
