<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Produccion extends Model
{
    protected $table = 'produccion';

    public $timestamps = false;

    /**
     * La tela apartada por la pieza sigue a su estado: terminada, se
     * descuenta; cancelada, se suelta.
     *
     * Va aquí y no en cada sitio que cierra o cancela una producción porque
     * son varios (completar el último paso, cambiar el estado a mano, la
     * pieza de Reserva) y basta olvidar uno para que la tela quede apartada
     * para siempre. Lo que cambia estado por consulta directa —cancelar la
     * orden entera— suelta la tela por su cuenta.
     */
    protected static function booted(): void
    {
        static::updated(function (Produccion $p) {
            if (! $p->wasChanged('estado')) return;

            $termino = in_array($p->estado, ['listo', 'entregado'], true);
            $cancelo = $p->estado === 'cancelado';
            if (! $termino && ! $cancelo) return;

            // La reserva cuelga del ítem de la orden, o de la producción
            // misma cuando se fabrica para la Reserva sin orden.
            if ($p->orden_item_id) {
                $termino
                    ? \App\Services\ConsumoTelas::consumirItem((int) $p->orden_item_id)
                    : \App\Services\ConsumoTelas::liberarItem((int) $p->orden_item_id);
            } elseif ($p->esReserva()) {
                $termino
                    ? \App\Services\ConsumoTelas::consumirProduccion((int) $p->id)
                    : \App\Services\ConsumoTelas::liberarProduccion((int) $p->id);
            }
        });
    }

    protected $fillable = [
        'orden_item_id',
        'destino',
        'producto_id',
        'variante_id',
        'combo_config_id',
        'variante_detalle',
        'cantidad',
        'specs',
        'creado_por',
        'depositado_at',
        'fecha_inicio',
        'fecha_compromiso',
        'fecha_real',
        'estado',
        'motivo_retraso',
        'despachado_por',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio'      => 'date',
            'fecha_compromiso'  => 'date',
            'fecha_real'        => 'date',
            'specs'             => 'array',
            'depositado_at'     => 'datetime',
        ];
    }

    public function ordenItem()
    {
        return $this->belongsTo(OrdenItem::class, 'orden_item_id');
    }

    public function pasos()
    {
        return $this->hasMany(ProduccionPaso::class, 'produccion_id')->orderBy('orden');
    }

    public function pasoActual()
    {
        return $this->hasOne(ProduccionPaso::class, 'produccion_id')
            ->whereIn('estado', ['en_proceso', 'pendiente'])
            ->orderBy('orden');
    }

    public function despachador()
    {
        return $this->belongsTo(Usuario::class, 'despachado_por');
    }

    /** Lo que se produce cuando la pieza no viene de una venta (destino=reserva). */
    public function producto()
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function variante()
    {
        return $this->belongsTo(ProductoVariante::class, 'variante_id');
    }

    public function comboConfig()
    {
        return $this->belongsTo(ProductoVarianteConfig::class, 'combo_config_id');
    }

    public function creador()
    {
        return $this->belongsTo(Usuario::class, 'creado_por');
    }

    /** ¿Se fabrica para la Reserva de Fábrica en vez de para una orden? */
    public function esReserva(): bool
    {
        return $this->destino === 'reserva';
    }

    /** El nombre del producto, venga de la orden o de las columnas directas. */
    public function productoNombre(): ?string
    {
        return $this->ordenItem?->producto?->nombre
            ?? $this->ordenItem?->nombre_custom
            ?? $this->producto?->nombre;
    }

    public function diasRestantes(): int
    {
        return now()->diffInDays($this->fecha_compromiso, false);
    }
}
