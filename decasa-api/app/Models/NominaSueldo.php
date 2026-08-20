<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un sueldo con nombre (ej. "Mínimo" = $60.000/día), para elegir al dar de
 * alta un trabajador en vez de escribir el valor cada vez. Quincena y mes
 * no se guardan — se calculan del valor_dia donde se necesiten mostrar.
 */
class NominaSueldo extends Model
{
    protected $table = 'nomina_sueldos';

    protected $fillable = ['nombre', 'valor_dia', 'activo'];

    protected function casts(): array
    {
        return [
            'valor_dia' => 'decimal:2',
            'activo'    => 'boolean',
        ];
    }

    public function empleados()
    {
        return $this->hasMany(Empleado::class, 'nomina_sueldo_id');
    }
}
