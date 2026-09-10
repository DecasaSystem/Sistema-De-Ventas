<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Un catálogo visual: una categoría con sus páginas maquetadas.
 *
 * El `slug` es la dirección pública ("/c/salas") y sale del nombre. Cambiar el
 * nombre no lo recalcula a propósito: un link ya compartido tiene que seguir
 * funcionando.
 */
class Catalogo extends Model
{
    protected $table = 'catalogos';

    protected $fillable = [
        'nombre', 'slug', 'descripcion', 'portada_url', 'activo', 'orden',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'orden'  => 'integer',
        ];
    }

    public function paginas()
    {
        return $this->hasMany(CatalogoPagina::class)->orderBy('orden')->orderBy('id');
    }

    /** Un slug libre a partir del nombre ("Salas y sofás" -> "salas-y-sofas"). */
    public static function slugLibre(string $nombre, ?int $ignorarId = null): string
    {
        $base = Str::slug($nombre) ?: 'catalogo';
        $slug = $base;
        $n    = 2;

        while (static::where('slug', $slug)
            ->when($ignorarId, fn ($q) => $q->where('id', '!=', $ignorarId))
            ->exists()
        ) {
            $slug = "{$base}-{$n}";
            $n++;
        }

        return $slug;
    }

    /** La imagen que representa el catálogo en la grilla. */
    public function portadaResuelta(): ?string
    {
        return $this->portada_url ?: $this->paginas()->value('imagen_url');
    }
}
