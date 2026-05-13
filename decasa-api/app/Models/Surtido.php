<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Surtido extends Model
{
    protected $table = 'surtidos';

    protected $fillable = ['supervisor_id', 'notas', 'estado'];

    public function supervisor()
    {
        return $this->belongsTo(Usuario::class, 'supervisor_id');
    }

    public function tiendas()
    {
        return $this->hasMany(SurtidoTienda::class, 'surtido_id');
    }
}
