<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un bono o descuento con nombre libre, encima del cálculo por días. */
class NominaAjuste extends Model
{
    protected $table = 'nomina_ajustes';

    protected $fillable = ['nomina_item_id', 'nombre', 'monto'];

    protected function casts(): array
    {
        return ['monto' => 'decimal:2'];
    }

    public function item()
    {
        return $this->belongsTo(NominaItem::class, 'nomina_item_id');
    }
}
