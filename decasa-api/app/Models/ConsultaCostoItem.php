<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsultaCostoItem extends Model
{
    protected $table = 'consulta_costo_items';

    protected $fillable = [
        'consulta_id',
        'orden_item_id',
        'precio_base',
        'margen_ganancia_pct',
        'precio_final',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'precio_base'         => 'decimal:2',
            'precio_final'        => 'decimal:2',
            'margen_ganancia_pct' => 'integer',
        ];
    }

    public function consulta()
    {
        return $this->belongsTo(ConsultaCosto::class, 'consulta_id');
    }

    public function ordenItem()
    {
        return $this->belongsTo(OrdenItem::class, 'orden_item_id');
    }

    public function desglose()
    {
        return $this->hasMany(ConsultaCostoDesglose::class, 'consulta_item_id');
    }
}
