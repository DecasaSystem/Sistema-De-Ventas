<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventarioVarianteCombinacion extends Model
{
    protected $table = 'inventario_variante_combinaciones';

    protected $fillable = [
        'variante_id',
        'config_id',
        'tienda_id',
        'cantidad_disponible',
        'cantidad_reservada',
    ];

    public function variante()
    {
        return $this->belongsTo(ProductoVariante::class, 'variante_id');
    }

    public function config()
    {
        return $this->belongsTo(ProductoVarianteConfig::class, 'config_id');
    }
}
