<?php

namespace App\Models;

use App\Services\CicloNomina;
use Illuminate\Database\Eloquent\Model;

/**
 * Un trabajador de nómina: mano de obra de taller que casi nunca tiene
 * cuenta en la app (a diferencia de Usuario, que sí inicia sesión).
 *
 * Su valor sale siempre de un sueldo del catálogo (`nomina_sueldo_id`), que
 * ya trae nombre, unidad (día u hora) y horas de jornada. Queda nullable en
 * la base por los 32 trabajadores que se importaron del Excel sin valor: se
 * ven con el aviso de "sin sueldo asignado" y no se pueden liquidar hasta
 * que se les elija uno.
 */
class Empleado extends Model
{
    protected $table = 'empleados';

    protected $fillable = ['nombre', 'cedula', 'cargo', 'nomina_sueldo_id', 'periodicidad', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function pagos()
    {
        return $this->hasMany(NominaPago::class);
    }

    public function ausencias()
    {
        return $this->hasMany(NominaAusencia::class);
    }

    public function ajustes()
    {
        return $this->hasMany(NominaAjuste::class);
    }

    public function sueldo()
    {
        return $this->belongsTo(NominaSueldo::class, 'nomina_sueldo_id');
    }

    /** Lo que gana en un día completo. Sin sueldo asignado todavía, 0. */
    public function valorDiaEfectivo(): float
    {
        return $this->sueldo?->valorDiaEquivalente() ?? 0.0;
    }

    /** Lo que gana por hora — con esto se descuentan las faltas parciales. */
    public function valorHoraEfectivo(): float
    {
        return $this->sueldo?->valorHoraEquivalente() ?? 0.0;
    }

    /** Cuántas horas dura un día completo para este trabajador. */
    public function horasDiaEfectivo(): float
    {
        return (float) ($this->sueldo?->horas_dia ?? 8);
    }

    /** El nombre del sueldo que tiene asignado. */
    public function labelEfectivo(): string
    {
        return $this->sueldo?->nombre ?? 'Sin sueldo asignado';
    }

    public function labelPeriodicidad(): string
    {
        return CicloNomina::label($this->periodicidad);
    }
}
