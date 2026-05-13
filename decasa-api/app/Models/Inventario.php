<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inventario extends Model
{
    protected $table = 'inventario';

    public $timestamps = false;

    protected $fillable = [
        'producto_id',
        'tienda_id',
        'cantidad_disponible',
        'cantidad_reservada',
        'stock_minimo',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function tienda()
    {
        return $this->belongsTo(Tienda::class, 'tienda_id');
    }
}
