<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una cuota ya descontada en un pago de nómina. */
class NominaPrestamoCuota extends Model
{
    protected $table = 'nomina_prestamo_cuotas';

    protected $fillable = ['prestamo_id', 'nomina_pago_id', 'monto', 'fecha'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'monto' => 'decimal:2'];
    }

    public function prestamo()
    {
        return $this->belongsTo(NominaPrestamo::class, 'prestamo_id');
    }
}
