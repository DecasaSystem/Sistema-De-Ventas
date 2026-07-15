<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EstimadoIa extends Model
{
    protected $table = 'estimados_ia';

    protected $fillable = [
        'input_texto', 'categoria', 'input_hash', 'medidas',
        'bom_json', 'precio_ia', 'requirio_revision', 'embedding',
        'precio_humano', 'error_pct', 'orden_item_id', 'corregido_por_id', 'corregido_at',
    ];

    protected $casts = [
        'medidas'           => 'array',
        'bom_json'          => 'array',
        'embedding'         => 'array',
        'requirio_revision' => 'boolean',
        'corregido_at'      => 'datetime',
    ];

    /** Texto normalizado que sirve de vínculo grueso input ↔ corrección del ebanista. */
    public static function hashInput(?string $nombre, ?string $categoria): string
    {
        $norm = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $nombre)));
        $cat  = mb_strtolower(trim((string) $categoria));

        return sha1($cat . '|' . $norm);
    }
}
