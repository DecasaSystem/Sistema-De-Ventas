<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * El porcentaje de un concepto desde una fecha. Cuando cambia la ley se
 * agrega una tarifa nueva con su "desde"; la vieja se queda para los ciclos
 * anteriores. No se edita una tarifa que ya rigió: se agrega otra.
 */
class NominaConceptoTarifa extends Model
{
    protected $table = 'nomina_concepto_tarifas';

    protected $fillable = ['concepto_id', 'porcentaje', 'desde', 'nota', 'creado_por'];

    protected function casts(): array
    {
        return [
            'porcentaje' => 'decimal:4',
            'desde'      => 'date',
        ];
    }

    public function concepto()
    {
        return $this->belongsTo(NominaConceptoEmpleador::class, 'concepto_id');
    }
}
