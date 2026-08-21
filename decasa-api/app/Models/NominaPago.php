<?php

namespace App\Models;

use App\Services\CicloNomina;
use Illuminate\Database\Eloquent\Model;

/**
 * Lo que se le pagó a un trabajador por un ciclo. Se crea recién al cobrar
 * y guarda el desglose completo congelado: subirle el sueldo mañana no
 * puede mover lo que ya se pagó.
 *
 * Es la única tabla "de operación" de la nómina — los ciclos en sí no se
 * guardan, se calculan (CicloNomina).
 */
class NominaPago extends Model
{
    protected $table = 'nomina_pagos';

    protected $fillable = [
        'usuario_id', 'periodicidad', 'fecha_inicio', 'fecha_fin', 'sueldo_nombre',
        'valor_dia', 'valor_hora', 'horas_dia', 'dias', 'subtotal',
        'descuento_faltas', 'total_ajustes', 'produccion_total', 'bonificacion',
        'bonificacion_nombre', 'bonificacion_detalle', 'total', 'observaciones', 'pagado_at',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio'     => 'date',
            'fecha_fin'        => 'date',
            'valor_dia'        => 'decimal:2',
            'valor_hora'       => 'decimal:2',
            'horas_dia'        => 'decimal:2',
            'dias'             => 'decimal:2',
            'subtotal'         => 'decimal:2',
            'descuento_faltas' => 'decimal:2',
            'total_ajustes'    => 'decimal:2',
            'produccion_total' => 'decimal:2',
            'bonificacion'     => 'decimal:2',
            'total'            => 'decimal:2',
            'pagado_at'        => 'datetime',
        ];
    }

    public function trabajador()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function ausencias()
    {
        return $this->hasMany(NominaAusencia::class);
    }

    public function ajustes()
    {
        return $this->hasMany(NominaAjuste::class);
    }

    public function producciones()
    {
        return $this->hasMany(NominaProduccion::class);
    }

    public function nombreCiclo(): string
    {
        return CicloNomina::nombre($this->periodicidad, $this->fecha_inicio, $this->fecha_fin);
    }

    public function labelPeriodicidad(): string
    {
        return CicloNomina::label($this->periodicidad);
    }
}
