<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TiendaAsesor extends Model
{
    protected $table = 'tienda_asesores_comision';

    protected $fillable = ['tienda_id', 'mes', 'vendedor_id'];

    public function tienda()
    {
        return $this->belongsTo(Tienda::class);
    }

    public function vendedor()
    {
        return $this->belongsTo(Usuario::class, 'vendedor_id');
    }
}
