<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Material extends Model
{
    protected $table = 'materiales';

    protected $fillable = [
        'nombre',
        'descripcion',
        'unidad',
        'unidad_norm',   // la pone el controlador con App\Support\UnidadMaterial
        'precio_unitario',
        'color',         // marca de color; qué significa lo dice App\Support\ColoresMaterial
    ];

    protected function casts(): array
    {
        return [
            'precio_unitario' => 'decimal:2',
        ];
    }

    /**
     * id del material de cada nombre, para enlazar ítems de ficha escritos a mano
     * con el catálogo. Compara sin mayúsculas ni espacios de los lados; si dos
     * materiales se llaman igual gana el más viejo.
     *
     * @param  iterable<string>  $nombres
     * @return array<string, int>  nombre normalizado => material_id
     */
    public static function idsPorNombre(iterable $nombres): array
    {
        $claves = collect($nombres)->map(fn ($n) => mb_strtolower(trim((string) $n)))->filter()->unique()->values();
        if ($claves->isEmpty()) return [];

        $ids = [];
        static::whereIn(DB::raw('LOWER(TRIM(nombre))'), $claves->all())
            ->orderBy('id')
            ->get(['id', 'nombre'])
            ->each(function ($m) use (&$ids) {
                $ids[mb_strtolower(trim($m->nombre))] ??= $m->id;
            });

        return $ids;
    }
}
