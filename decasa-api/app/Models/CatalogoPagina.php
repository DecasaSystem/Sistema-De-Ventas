<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una página de un catálogo visual. Es una imagen (extraída del PDF de diseño)
 * con su posición dentro del catálogo, una nota opcional (la ve el cliente) y
 * un nombre opcional (no lo ve el cliente: es para la tienda y para Elena, que
 * lo usa para saber qué hoja manda).
 */
class CatalogoPagina extends Model
{
    protected $table = 'catalogo_paginas';

    protected $fillable = [
        'catalogo_id', 'imagen_url', 'nombre', 'nota', 'orden',
    ];

    protected function casts(): array
    {
        return [
            'orden' => 'integer',
        ];
    }

    public function catalogo()
    {
        return $this->belongsTo(Catalogo::class);
    }
}
