<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * La fila de un empleado dentro de un período. El valor y la etiqueta se
 * copian del empleado al crear el período (no cambian con retroactividad si
 * después se le ajusta el salario base al empleado).
 */
class NominaItem extends Model
{
    protected $table = 'nomina_items';

    protected $fillable = ['nomina_periodo_id', 'empleado_id', 'valor_label', 'valor_base', 'dias_trabajados', 'observaciones'];

    protected function casts(): array
    {
        return [
            'valor_base'      => 'decimal:2',
            'dias_trabajados' => 'decimal:2',
        ];
    }

    public function periodo()
    {
        return $this->belongsTo(NominaPeriodo::class, 'nomina_periodo_id');
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }

    public function ajustes()
    {
        return $this->hasMany(NominaAjuste::class);
    }

    public function subtotal(): float
    {
        $diasPeriodo = $this->periodo?->dias_periodo ?: 1;
        return round((float) $this->valor_base / $diasPeriodo * (float) $this->dias_trabajados);
    }

    public function total(): float
    {
        return $this->subtotal() + $this->ajustes->sum(fn (NominaAjuste $a) => (float) $a->monto);
    }
}
