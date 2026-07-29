<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pago extends Model
{
    const UPDATED_AT = null;

    /**
     * Todo pago queda atado a la tienda donde entró el dinero. Cuando no se
     * indica —anticipos, cobro del conductor— es la tienda de la orden, que es
     * donde se está haciendo la venta. Solo el abono manual permite elegir otra.
     *
     * Va aquí y no en cada punto de creación porque hay una decena repartidos
     * entre varios controladores y basta olvidar uno para descuadrar una caja.
     */
    protected static function booted(): void
    {
        static::creating(function (Pago $pago) {
            if ($pago->tienda_id === null && $pago->orden_id) {
                $pago->tienda_id = Orden::where('id', $pago->orden_id)->value('tienda_id');
            }
        });
    }

    protected $fillable = [
        'orden_id',
        'vendedor_id',
        'tienda_id',
        'tipo',
        'monto',
        'metodo',
        'referencia',
        'notas',
        'comprobante_url',
    ];

    protected function casts(): array
    {
        return [
            'monto'               => 'decimal:2',
            'facturacion_hecha_at' => 'datetime',
        ];
    }

    public function orden()
    {
        return $this->belongsTo(Orden::class, 'orden_id');
    }

    public function vendedor()
    {
        return $this->belongsTo(Usuario::class, 'vendedor_id');
    }

    /** Tienda donde se recibió el dinero (puede no ser la de la orden). */
    public function tienda()
    {
        return $this->belongsTo(Tienda::class, 'tienda_id');
    }

    public function facturacionTomadaPor()
    {
        return $this->belongsTo(Usuario::class, 'facturacion_tomada_por');
    }
}
