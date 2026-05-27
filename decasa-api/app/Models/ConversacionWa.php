<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversacionWa extends Model
{
    protected $table = 'conversaciones_wa';

    protected $fillable = [
        'tipo', 'telefono', 'nombre_cliente', 'resumen',
        'historial', 'whatsapp_url', 'contacto_url', 'fuente', 'estado',
        'tomada_por', 'tomada_at', 'terminada_at',
    ];

    protected $casts = [
        'historial'   => 'array',
        'tomada_at'   => 'datetime',
        'terminada_at' => 'datetime',
    ];

    public function tomadaPor()
    {
        return $this->belongsTo(Usuario::class, 'tomada_por');
    }
}
