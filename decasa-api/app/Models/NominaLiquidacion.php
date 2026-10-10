<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * La liquidación de quien se retira, con todo el desglose congelado (salario
 * pendiente, prima, cesantías, intereses, vacaciones, indemnización y
 * descuentos). Ver App\Services\LiquidacionContrato.
 */
class NominaLiquidacion extends Model
{
    protected $table = 'nomina_liquidaciones';

    public const MOTIVOS = [
        'renuncia'                => 'Renuncia',
        'despido_justa_causa'     => 'Despido con justa causa',
        'despido_sin_justa_causa' => 'Despido sin justa causa',
        'fin_contrato'            => 'Terminación del contrato',
        'mutuo_acuerdo'           => 'Mutuo acuerdo',
    ];

    public const CONTRATOS = [
        'indefinido'  => 'Término indefinido',
        'fijo'        => 'Término fijo',
        'obra_labor'  => 'Obra o labor',
        'aprendizaje' => 'Aprendizaje',
        'servicios'   => 'Prestación de servicios',
    ];

    protected $fillable = [
        'usuario_id', 'fecha_ingreso', 'fecha_retiro', 'motivo', 'tipo_contrato', 'total', 'detalle',
        'estado', 'fecha_pago', 'notas', 'registrado_por', 'motivo_anulacion',
    ];

    protected function casts(): array
    {
        return [
            'fecha_ingreso' => 'date',
            'fecha_retiro'  => 'date',
            'fecha_pago'    => 'date',
            'total'         => 'decimal:2',
            'detalle'       => 'array',
        ];
    }

    public function trabajador()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function prestaciones()
    {
        return $this->hasMany(NominaPrestacionPago::class, 'liquidacion_id');
    }
}
