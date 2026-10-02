<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Producto extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'nombre',
        'categoria',
        'precio_base',
        'personalizable',
        'es_tapizado',
        'tiene_tallas',
        // Se vende en juego (unas mesas de a 2). El stock va en piezas; ver
        // la migración venta_por_juego. Se cambia por ProductoController::
        // ventaPorJuego, no por update: puede tener que convertir el stock.
        'piezas_por_juego',
        'precio_pieza',
        'descripcion',
        'foto_url',
        'foto_url_2',
        'medidas',
        'material',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'precio_base'    => 'decimal:2',
            'personalizable' => 'boolean',
            'es_tapizado'    => 'boolean',
            'tiene_tallas'   => 'boolean',
            'piezas_por_juego' => 'integer',
            'precio_pieza'   => 'decimal:2',
            'activo'         => 'boolean',
        ];
    }

    /** ¿Se vende en juego de varias piezas? */
    public function seVendeEnJuego(): bool
    {
        return (int) $this->piezas_por_juego > 1;
    }

    /** Precio de una pieza suelta: el propio, o el del juego repartido. */
    public function precioPieza(): float
    {
        if ($this->precio_pieza !== null) return (float) $this->precio_pieza;
        $n = max(1, (int) $this->piezas_por_juego);
        return floor((float) $this->precio_base / $n * 100) / 100;
    }

    public function inventarios()
    {
        return $this->hasMany(Inventario::class, 'producto_id');
    }

    public function ordenItems()
    {
        return $this->hasMany(OrdenItem::class, 'producto_id');
    }
}
