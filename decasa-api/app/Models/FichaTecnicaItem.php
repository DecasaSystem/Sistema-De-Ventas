<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FichaTecnicaItem extends Model
{
    protected $fillable = [
        'ficha_tecnica_id',
        'seccion',
        'descripcion',
        'cantidad',
        'unidad',
        'precio_unitario',
        'subtotal',
        'es_mano_obra',
        'orden',
    ];

    protected function casts(): array
    {
        return [
            'cantidad'        => 'decimal:4',
            'precio_unitario' => 'decimal:2',
            'subtotal'        => 'decimal:2',
            'es_mano_obra'    => 'boolean',
        ];
    }

    public function fichaTecnica()
    {
        return $this->belongsTo(FichaTecnica::class);
    }
}
