<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Produccion extends Model
{
    protected $table = 'produccion';

    public $timestamps = false;

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
