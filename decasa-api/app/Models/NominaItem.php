<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * La fila de un empleado dentro de un período. El valor, la etiqueta y las
 * horas por día se copian del empleado al crear el período (no cambian con
 * retroactividad si después se le ajusta el sueldo o la jornada).
 */
class NominaItem extends Model
{
    protected $table = 'nomina_items';

    protected $fillable = ['nomina_periodo_id', 'empleado_id', 'valor_label', 'valor_dia', 'horas_dia', 'dias_trabajados', 'observaciones'];

    protected function casts(): array
    {
        return [
            'valor_dia'       => 'decimal:2',
            'horas_dia'       => 'decimal:2',
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

    public function ausencias()
    {
        return $this->hasMany(NominaAusencia::class);
    }

    /** Lo que gana por hora este item, según sus propias horas_dia congeladas. */
    public function valorHora(): float
    {
        return $this->horas_dia > 0 ? round((float) $this->valor_dia / (float) $this->horas_dia, 2) : 0.0;
    }

    public function subtotal(): float
    {
        return round((float) $this->valor_dia * (float) $this->dias_trabajados);
    }

    /**
     * Lo que restan las ausencias registradas con fecha (horas × valor
     * hora), aparte del ajuste manual de `dias_trabajados`. Mismo patrón
     * que `ajustes`: una lista viva, no un número congelado.
     */
    public function descuentoAusencias(): float
    {
        $valorHora = $this->valorHora();
        return $this->ausencias->sum(fn (NominaAusencia $a) => round((float) $a->horas * $valorHora));
    }

    public function total(): float
    {
        return $this->subtotal()
            + $this->ajustes->sum(fn (NominaAjuste $a) => (float) $a->monto)
            - $this->descuentoAusencias();
    }
}
