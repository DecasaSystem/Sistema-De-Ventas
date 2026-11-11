<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una factura electrónica (o el intento de hacerla) en Alegra.
 *
 * `clave` es "orden:ID" cuando se factura la orden completa y "pago:ID" cuando
 * se factura cada pago: es única, así que lo mismo no se factura dos veces
 * aunque dos personas toquen el botón a la vez.
 */
class FacturaElectronica extends Model
{
    protected $table = 'facturas_electronicas';

    /** Ya está en Alegra y cuenta: no se vuelve a facturar. */
    public const ESTADOS_HECHOS = ['emitida', 'pendiente_dian', 'externa'];

    /** Hay que mirarla: no salió bien o no se sabe si salió. */
    public const ESTADOS_CON_PROBLEMA = ['rechazada', 'error', 'incierta'];

    protected $fillable = [
        'clave', 'orden_id', 'pago_id', 'tipo', 'estado', 'modo', 'alegra_id', 'alegra_contacto_id',
        'numero', 'cufe', 'estado_dian', 'pdf_url', 'base', 'iva', 'total', 'valor_sistema',
        'envio', 'respuesta', 'error', 'advertencias_aceptadas', 'nota', 'usuario_id', 'emitida_at',
        'diferencia_resuelta_at', 'diferencia_resuelta_por', 'diferencia_nota', 'pagos_alegra', 'retencion_cliente',
    ];

    protected function casts(): array
    {
        return [
            'envio'                  => 'array',
            'respuesta'              => 'array',
            'advertencias_aceptadas' => 'array',
            'pagos_alegra'           => 'array',
            'base'                   => 'decimal:2',
            'iva'                    => 'decimal:2',
            'total'                  => 'decimal:2',
            'valor_sistema'          => 'decimal:2',
            'retencion_cliente'      => 'decimal:2',
            'emitida_at'             => 'datetime',
            'diferencia_resuelta_at' => 'datetime',
        ];
    }

    public static function claveDe(Orden $orden, ?Pago $pago = null): string
    {
        return $pago ? "pago:{$pago->id}" : "orden:{$orden->id}";
    }

    public function orden()
    {
        return $this->belongsTo(Orden::class, 'orden_id');
    }

    public function pago()
    {
        return $this->belongsTo(Pago::class, 'pago_id');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    /** Pagos que debían quedar en Alegra y no quedaron (se pueden reintentar). */
    public function pagosSinRegistrar(): array
    {
        return array_values(array_filter((array) $this->pagos_alegra, fn ($p) => ($p['estado'] ?? '') !== 'registrado'));
    }

    public function estaHecha(): bool
    {
        return in_array($this->estado, self::ESTADOS_HECHOS, true);
    }
}
