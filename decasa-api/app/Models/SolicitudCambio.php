<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un cambio de plata que pidió un vendedor y espera a un supervisor.
 * Ver la migración create_solicitudes_cambio_table y App\Services\CambiosDePlata.
 */
class SolicitudCambio extends Model
{
    protected $table = 'solicitudes_cambio';

    public const PENDIENTE = 'pendiente';
    public const APROBADA  = 'aprobada';
    public const RECHAZADA = 'rechazada';
    public const CANCELADA = 'cancelada';

    protected $fillable = [
        'orden_id', 'solicitante_id', 'supervisor_id', 'estado', 'cambios_orden', 'cambio_pago', 'resumen',
        'motivo', 'soportes', 'revisado_por_id', 'revisado_at', 'respuesta',
    ];

    protected function casts(): array
    {
        return [
            'cambios_orden' => 'array',
            'cambio_pago'   => 'array',
            'resumen'       => 'array',
            'soportes'      => 'array',
            'revisado_at'   => 'datetime',
        ];
    }

    public function orden()
    {
        return $this->belongsTo(Orden::class);
    }

    public function solicitante()
    {
        return $this->belongsTo(Usuario::class, 'solicitante_id');
    }

    /** El supervisor al que se le pidió. */
    public function supervisor()
    {
        return $this->belongsTo(Usuario::class, 'supervisor_id');
    }

    public function revisadoPor()
    {
        return $this->belongsTo(Usuario::class, 'revisado_por_id');
    }
}
