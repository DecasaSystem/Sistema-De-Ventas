<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoVarianteConfig extends Model
{
    protected $table    = 'producto_variante_configs';
    protected $fillable = ['producto_id', 'tipo_variante_id', 'opcion_id', 'precio_adicional'];
    protected $casts    = ['precio_adicional' => 'decimal:2'];

    public function opcion()
    {
        return $this->belongsTo(TipoVarianteOpcion::class, 'opcion_id');
    }

    public function tipo()
    {
        return $this->belongsTo(TipoVariante::class, 'tipo_variante_id');
    }
}
