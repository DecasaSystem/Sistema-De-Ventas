<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una quincena de nómina. Una vez `pagado_at` tiene fecha queda congelada:
 * no se editan sus items ni ajustes — mismo criterio que ya usa
 * ComisionController::cerrarTrimestre() para no mover plata ya liquidada.
 */
class NominaPeriodo extends Model
{
    protected $table = 'nomina_periodos';

    protected $fillable = ['nombre', 'periodicidad', 'fecha_inicio', 'fecha_fin', 'dias_periodo', 'pagado_at'];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin'    => 'date',
            'dias_periodo' => 'integer',
            'pagado_at'    => 'datetime',
        ];
    }

    public function items()
    {
        return $this->hasMany(NominaItem::class);
    }

    public function estaPagado(): bool
    {
        return $this->pagado_at !== null;
    }

    public function totalGeneral(): float
    {
        return $this->items->sum(fn (NominaItem $i) => $i->total());
    }

    public function labelPeriodicidad(): string
    {
        return self::labelPara($this->periodicidad);
    }

    public static function labelPara(string $periodicidad): string
    {
        return match ($periodicidad) {
            'diario'    => 'Diario',
            'semanal'   => 'Semanal',
            'quincenal' => 'Quincenal',
            '20_dias'   => 'Cada 20 días',
            'mensual'   => 'Mensual',
            default     => ucfirst($periodicidad),
        };
    }
}
