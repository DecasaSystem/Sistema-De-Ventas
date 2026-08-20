<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un trabajador de nómina: mano de obra de taller que casi nunca tiene
 * cuenta en la app (a diferencia de Usuario, que sí inicia sesión).
 *
 * Su valor por día sale de una de dos fuentes: un sueldo con nombre del
 * catálogo (`nomina_sueldo_id`), o uno propio y personalizado (`valor_dia`
 * + `valor_label` directos en el empleado). Nunca las dos a la vez.
 */
class Empleado extends Model
{
    protected $table = 'empleados';

    protected $fillable = ['nombre', 'cedula', 'cargo', 'nomina_sueldo_id', 'valor_label', 'valor_dia', 'activo'];

    protected function casts(): array
    {
        return [
            'valor_dia' => 'decimal:2',
            'activo'    => 'boolean',
        ];
    }

    public function items()
    {
        return $this->hasMany(NominaItem::class);
    }

    public function sueldo()
    {
        return $this->belongsTo(NominaSueldo::class, 'nomina_sueldo_id');
    }

    /** El valor día real, venga del catálogo o sea personalizado. */
    public function valorDiaEfectivo(): float
    {
        return (float) ($this->sueldo?->valor_dia ?? $this->valor_dia ?? 0);
    }

    /** El nombre que se le muestra a ese valor, venga del catálogo o sea personalizado. */
    public function labelEfectivo(): string
    {
        return $this->sueldo?->nombre ?? ($this->valor_label ?: 'Personalizado');
    }
}
