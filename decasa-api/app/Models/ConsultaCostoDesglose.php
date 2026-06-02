<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsultaCostoDesglose extends Model
{
    protected $table = 'consulta_costo_desglose';

    protected $fillable = [
        'consulta_item_id',
        'tipo',
        'nombre',
        'cantidad',
        'precio_unitario',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'cantidad'       => 'decimal:3',
            'precio_unitario' => 'decimal:2',
            'subtotal'       => 'decimal:2',
        ];
    }

    public function consultaItem()
    {
        return $this->belongsTo(ConsultaCostoItem::class, 'consulta_item_id');
    }
}
