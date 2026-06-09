<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Producto extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'nombre',
        'categoria',
        'precio_base',
        'personalizable',
        'es_tapizado',
        'tiene_tallas',
        'descripcion',
        'foto_url',
        'foto_url_2',
        'medidas',
        'material',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'precio_base'    => 'decimal:2',
            'personalizable' => 'boolean',
            'es_tapizado'    => 'boolean',
            'tiene_tallas'   => 'boolean',
            'activo'         => 'boolean',
        ];
    }

    public function inventarios()
    {
        return $this->hasMany(Inventario::class, 'producto_id');
    }

    public function ordenItems()
    {
        return $this->hasMany(OrdenItem::class, 'producto_id');
    }
}
