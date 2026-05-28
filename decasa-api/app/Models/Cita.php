<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cita extends Model
{
    protected $table = 'citas';

    protected $fillable = [
        'conversacion_wa_id', 'asesor_id', 'tienda_id',
        'nombre_cliente', 'telefono', 'contacto_url', 'fuente',
        'dia', 'hora', 'motivo', 'estado', 'notas', 'fecha_cita',
    ];

    protected $casts = [
        'fecha_cita' => 'date:Y-m-d',
    ];

    public function asesor()
    {
        return $this->belongsTo(Usuario::class, 'asesor_id');
    }

    public function tienda()
    {
        return $this->belongsTo(Tienda::class, 'tienda_id');
    }

    public function conversacion()
    {
        return $this->belongsTo(ConversacionWa::class, 'conversacion_wa_id');
    }
}
