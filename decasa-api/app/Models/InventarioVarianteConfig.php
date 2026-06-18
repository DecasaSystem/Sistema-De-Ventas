<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventarioVarianteConfig extends Model
{
    protected $table    = 'inventario_variante_configs';
    protected $fillable = ['config_id', 'tienda_id', 'cantidad_disponible', 'cantidad_reservada'];

    public function config()
    {
        return $this->belongsTo(ProductoVarianteConfig::class, 'config_id');
    }
}
