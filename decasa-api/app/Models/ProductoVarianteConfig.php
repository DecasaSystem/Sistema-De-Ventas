<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoVarianteConfig extends Model
{
    protected $table    = 'producto_variante_configs';
    // piezas_por_juego: el juego de esta opción trae otro número de piezas
    // que el del producto (null = el del producto). Se cambia por
    // ProductoController::ventaPorJuego, que puede tener que convertir stock.
    protected $fillable = ['producto_id', 'tipo_variante_id', 'opcion_id', 'precio_adicional', 'piezas_por_juego'];
    protected $casts    = ['precio_adicional' => 'decimal:2', 'piezas_por_juego' => 'integer'];

    public function opcion()
    {
        return $this->belongsTo(TipoVarianteOpcion::class, 'opcion_id');
    }

    public function tipo()
    {
        return $this->belongsTo(TipoVariante::class, 'tipo_variante_id');
    }
}
