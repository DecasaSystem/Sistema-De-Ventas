<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * El porcentaje de un tipo de hora extra desde una fecha. Cuando cambia la ley
 * se agrega otra con su "desde"; la vieja se queda para las horas de antes.
 */
class NominaTipoHoraExtraTarifa extends Model
{
    protected $table = 'nomina_tipo_hora_extra_tarifas';

    protected $fillable = ['tipo_id', 'porcentaje', 'desde'];

    protected function casts(): array
    {
        return [
            'porcentaje' => 'decimal:4',
            'desde'      => 'date',
        ];
    }

    public function tipo()
    {
        return $this->belongsTo(NominaTipoHoraExtra::class, 'tipo_id');
    }
}
