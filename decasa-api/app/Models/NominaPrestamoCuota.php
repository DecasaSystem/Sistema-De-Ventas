<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una cuota ya descontada en un pago de nómina, o un abono que el trabajador
 * pagó por fuera (tipo = 'abono', sin pago de nómina).
 */
class NominaPrestamoCuota extends Model
{
    public const TIPO_ABONO = 'abono';

    protected $table = 'nomina_prestamo_cuotas';

    protected $fillable = ['prestamo_id', 'nomina_pago_id', 'tipo', 'monto', 'fecha', 'nota', 'registrado_por'];

    public function esAbono(): bool
    {
        return $this->tipo === self::TIPO_ABONO;
    }

    protected function casts(): array
    {
        return ['fecha' => 'date', 'monto' => 'decimal:2'];
    }

    public function prestamo()
    {
        return $this->belongsTo(NominaPrestamo::class, 'prestamo_id');
    }
}
