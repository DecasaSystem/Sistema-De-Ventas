<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Comision extends Model
{
    protected $table = 'comisiones';

    protected $fillable = [
        'orden_id', 'vendedor_id', 'tienda_id', 'origen', 'clave_unica', 'mes_venta',
        'valor_orden', 'fecha_venta', 'fecha_disponible',
        'estado', 'forma_pago_pagada', 'monto_comision', 'fecha_pago', 'pagada_por', 'notificado_lista',
        'reglas_usadas',
    ];

    /**
     * La foto de las reglas con que se pagó (ReglasComision::foto) es para
     * auditar, no para las pantallas de siempre: no viaja en cada renglón.
     */
    protected $hidden = ['reglas_usadas'];

    protected function casts(): array
    {
        return [
            'valor_orden'      => 'decimal:2',
            'monto_comision'   => 'decimal:2',
            'notificado_lista' => 'boolean',
            'fecha_pago'       => 'datetime',
            'reglas_usadas'    => 'array',
        ];
    }

    public function orden()
    {
        return $this->belongsTo(Orden::class);
    }

    public function vendedor()
    {
        return $this->belongsTo(Usuario::class, 'vendedor_id');
    }

    public function tienda()
    {
        return $this->belongsTo(Tienda::class);
    }

    public function pagadaPor()
    {
        return $this->belongsTo(Usuario::class, 'pagada_por');
    }
}
