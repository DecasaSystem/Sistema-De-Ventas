<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un bono o descuento con nombre libre (positivo suma, negativo resta),
 * anotado contra el trabajador y una fecha — igual que una falta. Cae solo
 * en el ciclo que contenga esa fecha y se engancha al pago cuando ese ciclo
 * se cobra; mientras tanto queda con `nomina_pago_id` en null.
 */
class NominaAjuste extends Model
{
    protected $table = 'nomina_ajustes';

    protected $fillable = ['empleado_id', 'nomina_pago_id', 'fecha', 'nombre', 'monto'];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'monto' => 'decimal:2',
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

    public function estaPagado(): bool
    {
        return $this->nomina_pago_id !== null;
    }
}
