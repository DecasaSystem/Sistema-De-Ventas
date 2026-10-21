<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un producto que volvió en el camión.
 *
 * Nace cuando el conductor lo registra en la entrega y se cierra cuando
 * alguien decide qué hacer: casi siempre vuelve al taller a que la arreglen,
 * y de vez en cuando se cancela y se le devuelve la plata al cliente.
 *
 * Mientras esté `pendiente`, la orden se queda en estado `devuelto`: ni
 * entregada —el camión se regresó con la mercancía— ni cancelada.
 */
class Devolucion extends Model
{
    protected $table = 'devoluciones';

    protected $fillable = [
        'orden_id', 'orden_item_id', 'despacho_item_id', 'cantidad',
        'motivo', 'foto_url', 'preferencia_cliente', 'fecha', 'reportado_por_id', 'estado',
        'decidido_por_id', 'decidido_at', 'notas_decision',
        'monto_devuelto', 'caja_movimiento_id',
        // Cambio de producto: a dónde va lo devuelto y cuándo se recoge
        // (migración 2026_10_27_000001_recogida_de_devoluciones).
        'destino', 'tienda_destino_id', 'recoger_el', 'recoger_con_entrega',
        'recibido_at', 'recibido_por_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha'          => 'date',
            'decidido_at'    => 'datetime',
            'monto_devuelto' => 'decimal:2',
            'recoger_el'     => 'date',
            'recoger_con_entrega' => 'boolean',
            'recibido_at'    => 'datetime',
        ];
    }

    public function orden()
    {
        return $this->belongsTo(Orden::class, 'orden_id');
    }

    public function item()
    {
        return $this->belongsTo(OrdenItem::class, 'orden_item_id');
    }

    public function reportadoPor()
    {
        return $this->belongsTo(Usuario::class, 'reportado_por_id');
    }

    public function decididoPor()
    {
        return $this->belongsTo(Usuario::class, 'decidido_por_id');
    }

    public function tiendaDestino()
    {
        return $this->belongsTo(Tienda::class, 'tienda_destino_id');
    }

    public function recibidoPor()
    {
        return $this->belongsTo(Usuario::class, 'recibido_por_id');
    }

    /**
     * ¿Falta que llegue el mueble? Solo las de cambio de producto con destino
     * llevan este control; las viejas (sin destino) ya se movieron solas.
     */
    public function porRecoger(): bool
    {
        return $this->destino !== null && $this->recibido_at === null;
    }

    public function scopeFaltaRecoger($query)
    {
        return $query->whereNotNull('destino')->whereNull('recibido_at');
    }

    public function estaPendiente(): bool
    {
        return $this->estado === 'pendiente';
    }

    /**
     * Lo que costaría devolverle al cliente por lo que se llevó de vuelta el
     * camión: lo que pagó por esas unidades. Es una sugerencia — quien decide
     * puede cobrar un arreglo o devolver de más por el disgusto.
     */
    public function montoSugerido(): float
    {
        return round((float) ($this->item?->precio_unitario ?? 0) * $this->cantidad, 2);
    }

    public function scopePendientes($query)
    {
        return $query->where('estado', 'pendiente');
    }
}
