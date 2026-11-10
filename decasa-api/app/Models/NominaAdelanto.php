<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sueldo entregado antes del día de pago, todo o una parte.
 *
 * Se descuenta en el próximo pago de nómina que se registre (lo más viejo
 * primero). Si es más que lo que ese pago le da, el pago queda en $0 y lo que
 * sobra pasa al siguiente como otra fila (`resto_de_id`): nunca un pago en
 * negativo.
 */
class NominaAdelanto extends Model
{
    protected $table = 'nomina_adelantos';

    protected $fillable = [
        'usuario_id', 'fecha', 'monto', 'nota', 'nomina_pago_id',
        'resto_de_id', 'liquidacion_id', 'registrado_por',
    ];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'monto' => 'decimal:2'];
    }

    public function trabajador()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function pago()
    {
        return $this->belongsTo(NominaPago::class, 'nomina_pago_id');
    }

    public function estaDescontado(): bool
    {
        return $this->nomina_pago_id !== null || $this->liquidacion_id !== null;
    }
}
