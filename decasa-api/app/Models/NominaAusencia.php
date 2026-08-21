<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una falta con fecha real y horas (para faltas parciales). Se registra
 * contra el trabajador y una fecha, y cae sola en el ciclo que contenga esa
 * fecha — incluido uno futuro: si alguien avisa hoy que va a faltar la
 * quincena que viene, la falta espera con `nomina_pago_id` en null y se
 * descuenta cuando esa quincena se cobre.
 */
class NominaAusencia extends Model
{
    protected $table = 'nomina_ausencias';

    protected $fillable = ['usuario_id', 'nomina_pago_id', 'fecha', 'horas', 'motivo'];

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
}
