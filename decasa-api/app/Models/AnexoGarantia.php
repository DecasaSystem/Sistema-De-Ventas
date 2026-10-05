<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un anexo de garantías firmado (o por firmar) dentro del sistema.
 * El texto vive en App\Support\AnexoGarantiaTexto. Ver la migración
 * 2026_10_12_000001_anexos_de_garantia.
 */
class AnexoGarantia extends Model
{
    protected $table = 'anexos_garantia';

    protected $fillable = [
        'token', 'orden_id', 'cliente_id', 'vendedor_id', 'version', 'modo', 'estado',
        'resumen', 'resumen_hash', 'respuestas', 'nombre_firmante', 'documento_firmante',
        'firma_url', 'firmado_at', 'ip', 'user_agent', 'vence_at',
    ];

    // El token es la llave del enlace: no sale en las respuestas de la API
    // salvo donde se pide a propósito (al crearlo, para armar el enlace).
    protected $hidden = ['token', 'ip', 'user_agent'];

    protected function casts(): array
    {
        return [
            'resumen'    => 'array',
            'respuestas' => 'array',
            'firmado_at' => 'datetime',
            'vence_at'   => 'datetime',
        ];
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(Orden::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'vendedor_id');
    }

    /** Si la base ya tiene la tabla (las pruebas montan su propio esquema). Sin caché: cambian de esquema. */
    public static function hayTabla(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable('anexos_garantia');
    }

    public function estaFirmado(): bool
    {
        return $this->estado === 'firmado';
    }

    public function vencido(): bool
    {
        return $this->estado === 'pendiente' && $this->vence_at && $this->vence_at->isPast();
    }

    /**
     * Huella de lo que el cliente vio: si la orden cambia, ya no cuadra.
     *
     * Se saca de las mismas líneas que viajan al crear la orden (producto o
     * nombre escrito, cantidad en piezas, precio unitario) y del total, así
     * que el resumen que firmó y la orden que llega se comparan igual.
     *
     * El total se saca de las mismas líneas menos los descuentos —y no del
     * total que calculó la pantalla—: en un juego el precio por pieza se
     * redondea al centavo, y dos sumas por caminos distintos no darían igual.
     *
     * @param array<int,array{producto_id?:int|null,nombre_custom?:string|null,cantidad:int|float,precio_unitario:int|float}> $lineas
     */
    public static function huella(array $lineas, float $descuentos): string
    {
        $subtotal = collect($lineas)->sum(fn ($i) => (float) ($i['cantidad'] ?? 0) * (float) ($i['precio_unitario'] ?? 0));
        $total    = max(0, $subtotal - $descuentos);

        $items = collect($lineas)
            ->map(fn ($i) => (! empty($i['producto_id'])
                    ? 'p' . (int) $i['producto_id']
                    : 'c:' . mb_strtolower(trim((string) ($i['nombre_custom'] ?? ''))))
                . '|' . (int) ($i['cantidad'] ?? 0)
                . '|' . round((float) ($i['precio_unitario'] ?? 0)))
            ->sort()->values()->all();

        return hash('sha256', json_encode(['total' => round($total), 'items' => $items]));
    }
}
