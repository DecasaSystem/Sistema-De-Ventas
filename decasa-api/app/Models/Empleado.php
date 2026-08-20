<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un trabajador de nómina: mano de obra de taller que casi nunca tiene
 * cuenta en la app (a diferencia de Usuario, que sí inicia sesión).
 *
 * Su valor sale de una de dos fuentes: un sueldo con nombre del catálogo
 * (`nomina_sueldo_id`), o uno propio y personalizado (`valor` + `unidad` +
 * `horas_dia` + `valor_label` directos en el empleado). Nunca las dos a la vez.
 */
class Empleado extends Model
{
    protected $table = 'empleados';

    protected $fillable = [
        'nombre', 'cedula', 'cargo', 'nomina_sueldo_id',
        'valor_label', 'valor', 'unidad', 'horas_dia', 'periodicidad', 'activo',
    ];

    protected function casts(): array
    {
        return [
            'valor'     => 'decimal:2',
            'horas_dia' => 'decimal:2',
            'activo'    => 'boolean',
        ];
    }

    public function items()
    {
        return $this->hasMany(NominaItem::class);
    }

    public function ausencias()
    {
        return $this->hasMany(NominaAusencia::class);
    }

    public function sueldo()
    {
        return $this->belongsTo(NominaSueldo::class, 'nomina_sueldo_id');
    }

    /** El valor día real, venga del catálogo o sea personalizado. */
    public function valorDiaEfectivo(): float
    {
        if ($this->sueldo) {
            return $this->sueldo->valorDiaEquivalente();
        }
        return $this->unidad === 'hora'
            ? round((float) $this->valor * (float) $this->horas_dia, 2)
            : (float) ($this->valor ?? 0);
    }

    /** El valor por hora real, venga del catálogo o sea personalizado. */
    public function valorHoraEfectivo(): float
    {
        if ($this->sueldo) {
            return $this->sueldo->valorHoraEquivalente();
        }
        if ($this->unidad === 'hora') {
            return (float) ($this->valor ?? 0);
        }
        return $this->horas_dia > 0 ? round((float) ($this->valor ?? 0) / (float) $this->horas_dia, 2) : 0.0;
    }

    /** Cuántas horas dura un día completo para este trabajador. */
    public function horasDiaEfectivo(): float
    {
        return (float) ($this->sueldo?->horas_dia ?? $this->horas_dia ?? 8);
    }

    /** El nombre que se le muestra a ese valor, venga del catálogo o sea personalizado. */
    public function labelEfectivo(): string
    {
        return $this->sueldo?->nombre ?? ($this->valor_label ?: 'Personalizado');
    }

    public function labelPeriodicidad(): string
    {
        return NominaPeriodo::labelPara($this->periodicidad);
    }
}
