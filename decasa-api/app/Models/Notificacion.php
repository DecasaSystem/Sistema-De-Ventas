<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notificacion extends Model
{
    protected $table = 'notificaciones';

    protected $fillable = ['usuario_id', 'tipo', 'titulo', 'mensaje', 'leida', 'datos'];

    protected $casts = [
        'leida' => 'boolean',
        'datos' => 'array',
    ];
}
