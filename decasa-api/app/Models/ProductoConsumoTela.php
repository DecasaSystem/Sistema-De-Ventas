<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cuánta tela lleva una unidad de un producto tapizado.
 *
 * Sin `config_id` es el consumo base del producto; con él, el de esa medida
 * concreta (ver ConsumoTelas::consumoDe).
 */
class ProductoConsumoTela extends Model
{
    protected $table = 'producto_consumo_telas';

    protected $fillable = ['producto_id', 'config_id', 'metros'];

    protected $casts = ['metros' => 'decimal:2'];

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function config()
    {
        return $this->belongsTo(ProductoVarianteConfig::class, 'config_id');
    }
}
