<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Un concepto de lo que la empresa paga por detrás de la nómina: pensión,
 * ARL, prima… (ver App\Services\CostoEmpleador). Es una fila y no una
 * constante porque la ley cambia: si sale un aporte nuevo se crea aquí, y si
 * uno deja de existir se desactiva (no se borra: los pagos viejos lo nombran).
 *
 * El porcentaje no vive aquí sino en sus tarifas, cada una con su "desde":
 * así un cambio de ley rige desde su fecha sin tocar los ciclos de antes.
 */
class NominaConceptoEmpleador extends Model
{
    protected $table = 'nomina_conceptos_empleador';

    public const GRUPOS = ['aportes', 'prestaciones', 'otros'];

    /**
     * Sobre qué se calcula: el sueldo devengado, el sueldo más el auxilio de
     * transporte (prima y cesantías), o el valor de otro concepto (intereses
     * de cesantías = 12 % de las cesantías).
     */
    public const BASES = ['salario', 'salario_auxilio', 'concepto'];

    protected $fillable = [
        'clave', 'nombre', 'grupo', 'base', 'base_concepto_id',
        'aplica_por_defecto', 'activo', 'orden', 'nota',
    ];

    protected function casts(): array
    {
        return [
            'aplica_por_defecto' => 'boolean',
            'activo'             => 'boolean',
            'orden'              => 'integer',
        ];
    }

    public function tarifas()
    {
        return $this->hasMany(NominaConceptoTarifa::class, 'concepto_id')->orderBy('desde');
    }

    public function baseConcepto()
    {
        return $this->belongsTo(self::class, 'base_concepto_id');
    }

    /**
     * El porcentaje que rige en una fecha: la última tarifa con `desde` hasta
     * ese día. Antes de la primera tarifa, la primera (no se deja un ciclo sin
     * costo por una fecha mal puesta).
     */
    public function porcentajeEn(CarbonInterface|string $fecha): float
    {
        $dia = $fecha instanceof CarbonInterface ? $fecha->toDateString() : substr((string) $fecha, 0, 10);
        $tarifas = $this->tarifas;
        $vigente = $tarifas->filter(fn ($t) => $t->desde->toDateString() <= $dia)->last()
            ?? $tarifas->first();

        return (float) ($vigente?->porcentaje ?? 0);
    }
}
