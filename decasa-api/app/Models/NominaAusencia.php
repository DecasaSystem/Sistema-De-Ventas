<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un día que el trabajador no trabajó. Se registra contra el trabajador y
 * una fecha, y cae sola en el ciclo que contenga esa fecha — incluido uno
 * futuro: si alguien avisa hoy que va a faltar la quincena que viene, espera
 * con `nomina_pago_id` en null y se descuenta cuando esa quincena se cobre.
 *
 * `tipo` decide cómo pega en el pago:
 *  - 'falta': se pierde el día — se descuenta `horas × valor_hora`.
 *  - 'incapacidad': el día se paga completo y solo se descuenta el auxilio
 *    de transporte (un valor fijo por día). `horas` no se usa.
 */
class NominaAusencia extends Model
{
    protected $table = 'nomina_ausencias';

    protected $fillable = ['usuario_id', 'nomina_pago_id', 'tipo', 'fecha', 'horas', 'motivo'];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'horas' => 'decimal:2',
        ];
    }

    public function trabajador()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function pago()
    {
        return $this->belongsTo(NominaPago::class, 'nomina_pago_id');
    }

    public function estaPagada(): bool
    {
        return $this->nomina_pago_id !== null;
    }

    public function esIncapacidad(): bool
    {
        return $this->tipo === 'incapacidad';
    }
}
