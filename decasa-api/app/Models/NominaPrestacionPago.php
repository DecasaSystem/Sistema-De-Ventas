<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una prestación que se pagó: la prima de un semestre, la consignación de las
 * cesantías al fondo, los intereses, unas vacaciones o una indemnización.
 * Lo que se DEBE no se guarda: sale de lo provisionado en cada pago de
 * nómina (App\Services\Prestaciones). Esto es lo que se resta.
 */
class NominaPrestacionPago extends Model
{
    protected $table = 'nomina_prestaciones_pagos';

    public const TIPOS = ['prima', 'cesantias', 'intereses_cesantias', 'vacaciones', 'indemnizacion'];
    public const FORMAS = ['pago', 'consignacion', 'con_nomina'];

    protected $fillable = [
        'usuario_id', 'tipo', 'periodo_desde', 'periodo_hasta', 'dias', 'monto', 'forma', 'destino',
        'fecha_pago', 'liquidacion_id', 'detalle', 'notas', 'estado', 'motivo_anulacion', 'registrado_por',
    ];

    protected function casts(): array
    {
        return [
            'periodo_desde' => 'date',
            'periodo_hasta' => 'date',
            'fecha_pago'    => 'date',
            'dias'          => 'decimal:2',
            'monto'         => 'decimal:2',
            'detalle'       => 'array',
        ];
    }

    public function trabajador()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function liquidacion()
    {
        return $this->belongsTo(NominaLiquidacion::class, 'liquidacion_id');
    }
}
