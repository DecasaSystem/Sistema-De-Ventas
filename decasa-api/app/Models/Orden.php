<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Orden extends Model
{
    protected $table = 'ordenes';

    protected $fillable = [
        'cliente_id',
        'vendedor_id',
        'tienda_id',
        'canal',
        'tipo',
        'estado',
        'numero_orden',
        'grupo_secuencia',
        'valor_total',
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
            'anticipo_pct'     => 'decimal:2',
            'es_compartida'    => 'boolean',
            'listo_entrega_at' => 'datetime',
        ];
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
