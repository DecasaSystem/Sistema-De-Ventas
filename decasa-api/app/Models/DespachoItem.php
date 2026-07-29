<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DespachoItem extends Model
{
    protected $table = 'despacho_items';

    public $timestamps = false;

    protected $fillable = [
        'despacho_id',
        'orden_id',
        'posicion',
        'estado',
        'foto_producto',
        'foto_pago',
        'entregado_at',
        // Acta de satisfacción firmada por quien recibe
        'firma_recibido_url',
        'recibido_por_nombre',
        'recibido_por_cedula',
        'conforme',
        'observaciones_entrega',
        'foto_novedad_url',
        'firma_omitida_motivo',
    ];

    protected function casts(): array
    {
        return [
            'entregado_at' => 'datetime',
            'conforme'     => 'boolean',
        ];
    }

    /** ¿La entrega quedó respaldada con firma o con un motivo de por qué no? */
    public function tieneActa(): bool
    {
        return $this->firma_recibido_url !== null || $this->firma_omitida_motivo !== null;
    }

    public function despacho()
    {
        return $this->belongsTo(Despacho::class, 'despacho_id');
    }

    public function orden()
    {
        return $this->belongsTo(Orden::class, 'orden_id');
    }

    public function tienePago(): bool
    {
        return $this->orden->pagos()
            ->where('created_at', '>=', $this->despacho->created_at)
            ->exists();
    }

    public function puedeEntregar(): bool
    {
        // Evidencia de llegada siempre obligatoria
        if ($this->foto_producto === null) return false;

        // Sin saldo pendiente: solo se necesita foto del producto
        if ($this->orden->saldoPendiente() <= 0.01) return true;

        // Con saldo: también foto del pago y pago registrado
        return $this->foto_pago !== null && $this->tienePago();
    }
}
