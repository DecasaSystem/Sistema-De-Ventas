<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Tienda;

class ConversacionWa extends Model
{
    protected $table = 'conversaciones_wa';

    protected $fillable = [
        'hash_idempotencia',
        'tipo', 'telefono', 'nombre_cliente', 'resumen',
        'historial', 'carrito', 'datos_cita', 'tienda_id',
        'whatsapp_url', 'contacto_url', 'fuente', 'estado',
        'tomada_por', 'tomada_at', 'terminada_at',
    ];

    protected $casts = [
        'historial'    => 'array',
        'carrito'      => 'array',
        'datos_cita'   => 'array',
        'tomada_at'    => 'datetime',
        'terminada_at' => 'datetime',
    ];

    public function tomadaPor()
    {
        return $this->belongsTo(Usuario::class, 'tomada_por');
    }

    public function tienda()
    {
        return $this->belongsTo(Tienda::class, 'tienda_id');
    }
}
