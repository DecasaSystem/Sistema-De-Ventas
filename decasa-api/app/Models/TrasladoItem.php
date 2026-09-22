<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrasladoItem extends Model
{
    protected $table = 'traslado_items';

    protected $fillable = [
        'traslado_id', 'producto_id', 'variante_id', 'combo_config_id',
        'cantidad', 'cantidad_aceptada',
    ];

    /** Qué tela/color se mandó, si el traslado lo dijo. */
    public function variante()
    {
        return $this->belongsTo(ProductoVariante::class, 'variante_id');
    }

    public function traslado()
    {
        return $this->belongsTo(Traslado::class, 'traslado_id');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }
}
