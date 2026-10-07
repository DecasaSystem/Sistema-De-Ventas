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
        if ($this->portada_url) return $this->portada_url;

        return $this->primeraPagina !== false
            ? $this->primeraPagina
            : $this->paginas()->value('imagen_url');
    }

    /**
     * La primera página de cada catálogo sin portada marcada, en UNA consulta.
     *
     * La grilla le preguntaba a la base por la primera página de cada
     * catálogo, uno por uno: la portada pública (/c) hacía 17 consultas, y
     * con la base lejos eso eran 3 segundos (medido). Se piden todas las
     * páginas de esos catálogos —solo el id del catálogo y la imagen— en el
     * mismo orden que `paginas()`, y a cada uno le queda la primera.
     */
    public static function precargarPortadas($catalogos): void
    {
        $sinPortada = collect($catalogos)->filter(fn (Catalogo $c) => ! $c->portada_url);
        if ($sinPortada->isEmpty()) return;

        $primeras = CatalogoPagina::whereIn('catalogo_id', $sinPortada->pluck('id'))
            ->orderBy('orden')->orderBy('id')
            ->get(['catalogo_id', 'imagen_url'])
            ->groupBy('catalogo_id')
            ->map(fn ($paginas) => $paginas->first()->imagen_url);

        $sinPortada->each(fn (Catalogo $c) => $c->primeraPagina = $primeras[$c->id] ?? null);
    }

    /** false = no se precargó (se pregunta a la base); null = no tiene páginas. */
    private string|null|false $primeraPagina = false;
}
