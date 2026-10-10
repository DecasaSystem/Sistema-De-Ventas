<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * La excepción de un trabajador a un concepto de lo que paga la empresa: a
 * él no se le paga pensión (`aplica = false`), o tiene su propio porcentaje
 * (la ARL del taller, riesgo 3). Sin fila, rige lo de la empresa.
 */
class NominaConceptoTrabajador extends Model
{
    protected $table = 'nomina_concepto_trabajador';

    protected $fillable = ['usuario_id', 'concepto_id', 'aplica', 'porcentaje'];

    protected function casts(): array
    {
        return [
            'aplica'     => 'boolean',
            'porcentaje' => 'decimal:4',
        ];
    }

    public function concepto()
    {
        return $this->belongsTo(NominaConceptoEmpleador::class, 'concepto_id');
    }
}
