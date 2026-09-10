<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una página de un catálogo visual. Es una imagen (extraída del PDF de diseño)
 * con su posición dentro del catálogo y una nota opcional.
 */
class CatalogoPagina extends Model
{
    protected $table = 'catalogo_paginas';

    protected $fillable = [
        'catalogo_id', 'imagen_url', 'nota', 'orden',
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
