<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plata que se le adelantó a un trabajador y se le descuenta por cuotas.
 *
 * El saldo NO se guarda: se calcula sumando las cuotas ya descontadas. Un
 * saldo guardado se desincroniza en cuanto se anula un pago, y ahí la deuda
 * queda mal para siempre sin que nadie se entere.
 */
class NominaPrestamo extends Model
{
    protected $table = 'nomina_prestamos';

    protected $fillable = [
        'usuario_id', 'motivo', 'monto', 'cuotas', 'valor_cuota',
        'fecha', 'creado_por', 'activo',
    ];

    protected function casts(): array
    {
        return [
            'fecha'       => 'date',
            'monto'       => 'decimal:2',
            'valor_cuota' => 'decimal:2',
            'activo'      => 'boolean',
        ];
    }

    public function trabajador()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function cuotasPagadas()
    {
        return $this->hasMany(NominaPrestamoCuota::class, 'prestamo_id');
    }

    /** Cuánto se le ha descontado ya. */
    public function abonado(): float
    {
        return (float) ($this->relationLoaded('cuotasPagadas')
            ? $this->cuotasPagadas->sum('monto')
            : $this->cuotasPagadas()->sum('monto'));
    }

    /** Cuánto falta por descontar. Nunca menos de cero. */
    public function saldo(): float
    {
        return max(0, round((float) $this->monto - $this->abonado(), 2));
    }

    public function saldado(): bool
    {
        return $this->saldo() <= 0;
    }

    /**
     * Lo que toca descontar en el próximo pago.
     *
     * La última cuota se ajusta al saldo: si el préstamo no divide exacto, la
     * cuota fija terminaría cobrando de más y quedando la deuda en negativo.
     */
    public function cuotaDelProximoPago(): float
    {
        if (! $this->activo || $this->saldado()) {
            return 0.0;
        }

        return min((float) $this->valor_cuota, $this->saldo());
    }
}
