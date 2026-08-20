<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un escalón de la bonificación: "de 2.800.000 a 2.900.000 son 80.000".
 * `hasta` en null es el último, el de "de aquí en adelante".
 */
class NominaBonificacionMeta extends Model
{
    protected $table = 'nomina_bonificacion_metas';

    protected $fillable = ['nomina_bonificacion_id', 'desde', 'hasta', 'monto', 'activo'];

    protected function casts(): array
    {
        return [
            'desde'  => 'decimal:2',
            'hasta'  => 'decimal:2',
            'monto'  => 'decimal:2',
            'activo' => 'boolean',
        ];
    }

    public function bonificacion()
    {
        return $this->belongsTo(NominaBonificacion::class, 'nomina_bonificacion_id');
    }

    public function etiqueta(): string
    {
        $desde = '$' . number_format((float) $this->desde, 0, ',', '.');

        if ($this->hasta === null) {
            return "de {$desde} en adelante";
        }

        return "de {$desde} a $" . number_format((float) $this->hasta, 0, ',', '.');
    }
}
