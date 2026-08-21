<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un sueldo con nombre (ej. "Mínimo"), para elegir al dar de alta un
 * trabajador en vez de escribir el valor cada vez. Puede definirse por día
 * o por hora — no hay una jornada estándar única para todos (taller y
 * oficina, gente que trabaja distintas horas, gente que cobra por hora sin
 * contrato fijo), así que `horas_dia` se configura aquí mismo.
 */
class NominaSueldo extends Model
{
    protected $table = 'nomina_sueldos';

    protected $fillable = ['nombre', 'valor', 'unidad', 'horas_dia', 'activo'];

    protected function casts(): array
    {
        return [
            'valor'     => 'decimal:2',
            'horas_dia' => 'decimal:2',
            'activo'    => 'boolean',
        ];
    }

    public function trabajadores()
    {
        return $this->hasMany(Usuario::class, 'nomina_sueldo_id');
    }

    /** Lo que gana en un día completo, sea cual sea la unidad en que se cargó. */
    public function valorDiaEquivalente(): float
    {
        return $this->unidad === 'hora'
            ? round((float) $this->valor * (float) $this->horas_dia, 2)
            : (float) $this->valor;
    }

    /** Lo que gana por hora, sea cual sea la unidad en que se cargó. */
    public function valorHoraEquivalente(): float
    {
        if ($this->unidad === 'hora') {
            return (float) $this->valor;
        }
        return $this->horas_dia > 0 ? round((float) $this->valor / (float) $this->horas_dia, 2) : 0.0;
    }
}
