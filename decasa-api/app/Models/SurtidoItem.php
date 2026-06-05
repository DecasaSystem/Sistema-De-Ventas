<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SurtidoItem extends Model
{
    protected $table = 'surtido_items';

    public $timestamps = false;

    protected $fillable = [
        'surtido_tienda_id',
        'producto_id',
        'cantidad',
        'cantidad_aceptada',
        'especificaciones',
    ];

    protected $casts = [
        'especificaciones' => 'array',
    ];

    public function surtidoTienda()
    {
        return $this->belongsTo(SurtidoTienda::class, 'surtido_tienda_id');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }
}
