<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una cosa que rebaja una promoción: un producto con todas sus versiones, una
 * versión suya (opción o tela) o el producto sin versiones. Ver la migración
 * promociones_varios_productos.
 */
class PromocionProducto extends Model
{
    protected $table = 'promocion_productos';

    public $timestamps = false;

    protected $fillable = ['promocion_id', 'producto_id', 'config_id', 'variante_id', 'sin_versiones'];

    protected function casts(): array
    {
        return ['sin_versiones' => 'boolean'];
    }

    public function promocion()
    {
        return $this->belongsTo(Promocion::class, 'promocion_id');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function config()
    {
        return $this->belongsTo(ProductoVarianteConfig::class, 'config_id');
    }

    public function variante()
    {
        return $this->belongsTo(ProductoVariante::class, 'variante_id');
    }

    /** todas | algunas | sin_versiones — lo que se le preguntó al escoger el producto. */
    public function alcance(): string
    {
        if ($this->config_id || $this->variante_id) return 'algunas';
        return $this->sin_versiones ? 'sin_versiones' : 'todas';
    }

    /**
     * ¿Se puede decir que esta línea se vendió con ella? Tiene que ser el
     * mismo producto, y la opción o la tela no pueden contradecirla. Si la
     * línea no trae opción no se rechaza: con dos opciones elegidas (1.40 y
     * con baúl) la orden guarda solo el texto, y la pantalla sí sabía cuáles.
     * "Sin versiones" sí exige que la línea no traiga ninguna.
     */
    public function leAplicaA(int $productoId, ?int $configId, ?int $varianteId): bool
    {
        if ((int) $this->producto_id !== $productoId) return false;
        if ($this->sin_versiones) return ! $configId && ! $varianteId;
        if ($this->config_id && $configId && (int) $this->config_id !== $configId) return false;
        if ($this->variante_id && $varianteId && (int) $this->variante_id !== $varianteId) return false;
        return true;
    }

    /**
     * Precio de lista de lo que se rebaja, como lo pone Nueva orden: el de la
     * opción si tiene uno (ojo: `precio_adicional` se usa como precio completo
     * de esa versión, no como recargo), el de la tela si tiene, o el del producto.
     */
    public function precioDeLista(): float
    {
        $base = (float) ($this->producto?->precio_base ?? 0);
        if ($this->config_id) {
            $p = (float) ($this->config?->precio_adicional ?? 0);
            return $p > 0 ? $p : $base;
        }
        if ($this->variante_id && $this->variante?->precio_variante !== null) {
            return (float) $this->variante->precio_variante;
        }
        return $base;
    }

    /** "1.40", "Gris · Pana": de cuál versión es. null = el producto entero (o sin versiones). */
    public function textoVersion(): ?string
    {
        if ($this->config_id) return $this->config?->opcion?->nombre;
        if ($this->variante_id) {
            $v = $this->variante;
            if (! $v) return null;
            return $v->medida ?: (collect([$v->marca, $v->marca_tela, $v->nombre_color])->filter()->implode(' · ') ?: null);
        }
        return null;
    }

    /** Para comparar dos filas: mismo producto y misma versión. */
    public function llave(): string
    {
        return self::llaveDe((int) $this->producto_id, $this->config_id, $this->variante_id, (bool) $this->sin_versiones);
    }

    public static function llaveDe(int $productoId, $configId, $varianteId, bool $sinVersiones): string
    {
        return $productoId . ':' . (int) $configId . ':' . (int) $varianteId . ':' . ($sinVersiones ? 1 : 0);
    }
}
