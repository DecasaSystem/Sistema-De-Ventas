<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Traslado extends Model
{
    protected $table = 'traslados';

    protected $fillable = ['supervisor_id', 'tienda_origen_id', 'tienda_destino_id', 'notas'];

    public function supervisor()
    {
        return $this->belongsTo(Usuario::class, 'supervisor_id');
    }

    public function tiendaOrigen()
    {
        return $this->belongsTo(Tienda::class, 'tienda_origen_id');
    }

    public function tiendaDestino()
    {
        return $this->belongsTo(Tienda::class, 'tienda_destino_id');
    }

    public function items()
    {
        return $this->hasMany(TrasladoItem::class, 'traslado_id');
    }
}
