<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Un tipo de hora extra o recargo ("Extra diurna", "Dominical o festivo"), con
 * su porcentaje por fechas (NominaTipoHoraExtraTarifa).
 *
 * - `extra`: se paga la hora completa más el porcentaje (hora × 1,25). Es
 *   tiempo por encima de la jornada.
 * - `recargo`: la hora ya va en el sueldo del día; solo se suma el porcentaje
 *   (hora × 0,35 de nocturno). Es tiempo dentro de la jornada, en horario
 *   nocturno o en domingo/festivo.
 */
class NominaTipoHoraExtra extends Model
{
    protected $table = 'nomina_tipos_hora_extra';

    public const CALCULOS = ['extra', 'recargo'];

    protected $fillable = ['clave', 'nombre', 'calculo', 'activo', 'orden'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function tarifas()
    {
        return $this->hasMany(NominaTipoHoraExtraTarifa::class, 'tipo_id')->orderBy('desde');
    }

    /** El porcentaje que rige ese día (o el más viejo, si la fecha es anterior a todos). */
    public function porcentajeEn(CarbonInterface|string $fecha): float
    {
        $dia = $fecha instanceof CarbonInterface ? $fecha->toDateString() : substr((string) $fecha, 0, 10);
        $tarifas = $this->tarifas;
        $vigente = $tarifas->filter(fn ($t) => $t->desde->toDateString() <= $dia)->last() ?? $tarifas->first();

        return (float) ($vigente?->porcentaje ?? 0);
    }

    /** Cuántas veces el valor hora vale una hora de este tipo ese día: 1,25 o 0,35. */
    public function factorEn(CarbonInterface|string $fecha): float
    {
        $pct = $this->porcentajeEn($fecha) / 100;

        return $this->calculo === 'recargo' ? $pct : 1 + $pct;
    }
}
