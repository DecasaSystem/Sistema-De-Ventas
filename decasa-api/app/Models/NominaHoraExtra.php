<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Horas extra (o con recargo) que trabajó alguien un día. Como las faltas:
 * cuelga del trabajador y la fecha, cae en el ciclo que contiene esa fecha y
 * al pagarse queda enganchada al pago (`nomina_pago_id`).
 *
 * El valor no se guarda aquí: se calcula al liquidar el ciclo (valor hora del
 * sueldo × factor del tipo ese día) y se congela en el pago
 * (`nomina_pagos.horas_extra_detalle`).
 */
class NominaHoraExtra extends Model
{
    protected $table = 'nomina_horas_extra';

    /** Topes de ley (CST art. 22 Ley 50/1990): se avisa, no se frena. */
    public const TOPE_DIA    = 2;
    public const TOPE_SEMANA = 12;

    protected $fillable = ['usuario_id', 'tipo_id', 'nomina_pago_id', 'fecha', 'horas', 'nota', 'registrado_por'];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'horas' => 'decimal:2',
        ];
    }

    public function trabajador()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function tipo()
    {
        return $this->belongsTo(NominaTipoHoraExtra::class, 'tipo_id');
    }

    public function pago()
    {
        return $this->belongsTo(NominaPago::class, 'nomina_pago_id');
    }

    public function estaPagada(): bool
    {
        return $this->nomina_pago_id !== null;
    }

    /** Lo que vale con este valor hora (el del sueldo del trabajador). */
    public function valorCon(float $valorHora): float
    {
        return round((float) $this->horas * $valorHora * ($this->tipo?->factorEn($this->fecha) ?? 0));
    }
}
