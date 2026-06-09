<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoVariante extends Model
{
    protected $table = 'producto_variantes';

    protected $fillable = ['producto_id', 'marca', 'marca_tela', 'nombre_color', 'medida', 'precio_variante', 'foto_url', 'activo'];

    protected $casts = ['activo' => 'boolean', 'precio_variante' => 'decimal:2'];

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function inventarios()
    {
        return $this->hasMany(InventarioVariante::class, 'variante_id');
    }
}
