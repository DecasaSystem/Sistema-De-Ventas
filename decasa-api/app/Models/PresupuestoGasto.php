<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cuánto se piensa gastar en una categoría un mes, para compararlo con lo real. */
class PresupuestoGasto extends Model
{
    protected $table = 'presupuestos_gasto';

    protected $fillable = ['categoria_gasto_id', 'mes', 'monto'];

    protected function casts(): array
    {
        return ['monto' => 'decimal:2'];
    }

    public function categoria()
    {
        return $this->belongsTo(CategoriaGasto::class, 'categoria_gasto_id');
    }
}
