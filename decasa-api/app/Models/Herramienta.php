<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un dato que el asesor copia mientras atiende: una dirección, un horario,
 * un enlace a un catálogo.
 */
class Herramienta extends Model
{
    protected $table = 'herramientas';

    /** Qué se puede hacer con el contenido, además de copiarlo. */
    public const TIPOS = ['texto', 'direccion', 'enlace'];

    /**
     * `clave` no está: la ponen las migraciones para lo que algo más busca por
     * nombre (los catálogos que consume el bot). Una herramienta creada desde
     * Gestión va sin clave, y una que ya la tiene no la pierde al editarla.
     */
    protected $fillable = [
        'seccion', 'titulo', 'tipo', 'contenido', 'subtitulo', 'icono', 'activo', 'orden',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'orden'  => 'integer',
        ];
    }
}
