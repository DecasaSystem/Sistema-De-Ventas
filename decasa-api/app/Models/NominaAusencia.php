<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una falta con fecha real y horas (para faltas parciales). Se registra
 * contra el empleado y una fecha, no contra un item de período, porque la
 * fecha puede caer en una quincena que todavía no existe — queda con
 * `nomina_item_id` en null (pendiente) hasta que ese período se cree.
 */
class NominaAusencia extends Model
{
    protected $table = 'nomina_ausencias';

    protected $fillable = ['empleado_id', 'nomina_item_id', 'fecha', 'horas', 'motivo'];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'horas' => 'decimal:2',
        ];
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }

    public function item()
    {
        return $this->belongsTo(NominaItem::class, 'nomina_item_id');
    }

    public function estaPendiente(): bool
    {
        return $this->nomina_item_id === null;
    }
}
