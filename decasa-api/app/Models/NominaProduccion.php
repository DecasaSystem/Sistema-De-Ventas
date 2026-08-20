<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Algo que el trabajador hizo y que suma para su bonificación: "base cama
 * redonda $30.000", "silla blanca $10.000 × 4". Mismo patrón que faltas y
 * ajustes — cuelga del trabajador y una fecha, y cae en el ciclo que
 * contenga esa fecha, con `nomina_pago_id` en null hasta que se cobra.
 */
class NominaProduccion extends Model
{
    protected $table = 'nomina_producciones';

    protected $fillable = [
        'empleado_id', 'nomina_pago_id', 'fecha', 'concepto',
        'valor_unitario', 'cantidad', 'total',
    ];

    protected function casts(): array
    {
        return [
            'fecha'          => 'date',
            'valor_unitario' => 'decimal:2',
            'cantidad'       => 'decimal:2',
            'total'          => 'decimal:2',
        ];
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }

    public function pago()
    {
        return $this->belongsTo(NominaPago::class, 'nomina_pago_id');
    }

    public function estaPagada(): bool
    {
        return $this->nomina_pago_id !== null;
    }
}
