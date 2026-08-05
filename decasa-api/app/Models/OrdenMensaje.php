<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdenMensaje extends Model
{
    protected $table = 'orden_mensajes';

    protected $fillable = ['orden_id', 'usuario_id', 'mensaje', 'mencionados'];

    protected function casts(): array
    {
        return [
            'mencionados' => 'array',
        ];
    }

    public function orden()
    {
        return $this->belongsTo(Orden::class, 'orden_id');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
