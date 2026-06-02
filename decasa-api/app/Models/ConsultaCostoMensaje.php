<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsultaCostoMensaje extends Model
{
    protected $table = 'consulta_costo_mensajes';

    protected $fillable = ['consulta_id', 'usuario_id', 'mensaje'];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function consulta()
    {
        return $this->belongsTo(ConsultaCosto::class, 'consulta_id');
    }
}
