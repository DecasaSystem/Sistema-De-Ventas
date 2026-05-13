<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventarioVariante extends Model
{
    protected $table    = 'inventario_variantes';
    public    $timestamps = false;

    protected $fillable = ['variante_id', 'tienda_id', 'cantidad_disponible', 'cantidad_reservada', 'stock_minimo'];

    public function variante()
    {
        return $this->belongsTo(ProductoVariante::class, 'variante_id');
    }

    public function tienda()
    {
        return $this->belongsTo(Tienda::class);
    }
}
