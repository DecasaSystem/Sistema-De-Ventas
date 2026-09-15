<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un sueldo con nombre (ej. "Mínimo"), para elegir al dar de alta un
 * trabajador en vez de escribir el valor cada vez. Puede definirse por día
 * o por hora — no hay una jornada estándar única para todos (taller y
 * oficina, gente que trabaja distintas horas, gente que cobra por hora sin
 * contrato fijo), así que `horas_dia` se configura aquí mismo.
 *
 * Trae también el auxilio de transporte y la seguridad social AL MES, que es
 * como los publica el decreto: el sistema los prorratea por los días del
 * ciclo (× días / 30). Si un trabajador los recibe o no se decide en su
 * ficha (`nomina_auxilio`, `nomina_seguridad_social`), porque el mismo
 * sueldo lo comparten unos con y otros sin.
 */
class NominaSueldo extends Model
{
    /**
     * Auxilio de transporte mensual que trae un sueldo nuevo. Es el valor
     * nacional 2026; cambia por decreto cada año y se edita desde la pantalla
     * de Sueldos.
     */
    public const AUXILIO_MES_DEFECTO = 249095;

    /** Un mes de nómina son 30 días, gane uno por día o por hora. */
    public const DIAS_MES = 30;

    protected $table = 'nomina_sueldos';

    protected $fillable = ['nombre', 'valor', 'unidad', 'horas_dia', 'valor_auxilio_mes', 'valor_seguridad_social_mes', 'activo'];

    protected function casts(): array
    {
        return [
            'valor'                      => 'decimal:2',
            'horas_dia'                  => 'decimal:2',
            'valor_auxilio_mes'          => 'decimal:2',
            'valor_seguridad_social_mes' => 'decimal:2',
            'activo'                     => 'boolean',
        ];
    }

    /**
     * Auxilio de transporte por un día. Es lo que se suma por día trabajado y
     * lo que se descuenta por cada día de incapacidad. 0 = sin auxilio.
     *
     * Sin redondear: los pesos se redondean una sola vez, al final de la
     * cuenta del ciclo, para que 15 días den 124.548 y no 124.545.
     */
    public function valorAuxilioDia(): float
    {
        return (float) $this->valor_auxilio_mes / self::DIAS_MES;
    }

    /** Seguridad social por un día. 0 = no se descuenta. */
    public function valorSeguridadSocialDia(): float
    {
        return (float) $this->valor_seguridad_social_mes / self::DIAS_MES;
    }

    /** Un valor mensual llevado a los días de un ciclo: × días / 30. */
    public static function prorratear(float $valorMes, float $dias): float
    {
        return round($valorMes * $dias / self::DIAS_MES);
    }

    public function trabajadores()
    {
        return $this->hasMany(Usuario::class, 'nomina_sueldo_id');
    }

    /** Lo que gana en un día completo, sea cual sea la unidad en que se cargó. */
    public function valorDiaEquivalente(): float
    {
        return $this->unidad === 'hora'
            ? round((float) $this->valor * (float) $this->horas_dia, 2)
            : (float) $this->valor;
    }

    /** Lo que gana por hora, sea cual sea la unidad en que se cargó. */
    public function valorHoraEquivalente(): float
    {
        if ($this->unidad === 'hora') {
            return (float) $this->valor;
        }
        return $this->horas_dia > 0 ? round((float) $this->valor / (float) $this->horas_dia, 2) : 0.0;
    }
}
