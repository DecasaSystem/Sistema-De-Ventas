<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SurtidoItem extends Model
{
    protected $table = 'surtido_items';

    public $timestamps = false;

    protected $fillable = [
        'surtido_tienda_id',
        'producto_id',
        'variante_id',
        'combo_config_id',
        'cantidad',
        'cantidad_aceptada',
        'especificaciones',
    ];

    protected $casts = [
        'especificaciones' => 'array',
    ];

    public function surtidoTienda()
    {
        return $this->belongsTo(SurtidoTienda::class, 'surtido_tienda_id');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    /**
     * Qué versión del producto se manda. Sin esto, en la remisión impresa
     * ocho sillas iguales de colores distintos salen como ocho líneas
     * idénticas y no hay forma de comprobar lo que llegó.
     */
    public function variante()
    {
        return $this->belongsTo(ProductoVariante::class, 'variante_id');
    }

    public function comboConfig()
    {
        return $this->belongsTo(ProductoVarianteConfig::class, 'combo_config_id');
    }

    /** Cómo se llama esa versión en una línea: "Rojo · Tela X". */
    public function detalleVariante(): ?string
    {
        $partes = [];

        if ($this->variante) {
            $partes[] = $this->variante->nombre ?? $this->variante->color ?? null;
        }
        if ($this->comboConfig) {
            $partes[] = trim(($this->comboConfig->tipo?->nombre ?? '') . ' ' . ($this->comboConfig->opcion?->nombre ?? ''));
        }
        foreach ((array) $this->especificaciones as $clave => $valor) {
            if (is_scalar($valor) && $valor !== '') {
                $partes[] = is_string($clave) ? "{$clave}: {$valor}" : (string) $valor;
            }
        }

        $partes = array_filter($partes, fn ($p) => $p !== null && $p !== '');

        return $partes ? implode(' · ', $partes) : null;
    }
}
