<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un producto (o una versión suya) más barato en unas tiendas, de una fecha a
 * otra. Ver la migración promociones_por_tienda.
 *
 * El precio de lista no cambia: la pantalla de Nueva orden le aplica la
 * rebaja a la línea (como el descuento por producto) y la orden guarda el
 * precio ya rebajado más `promocion_id`.
 */
class Promocion extends Model
{
    protected $table = 'promociones';

    public const TIPOS = ['porcentaje', 'monto', 'precio'];

    protected $fillable = [
        'nombre', 'producto_id', 'config_id', 'variante_id',
        'tipo', 'valor', 'fecha_inicio', 'fecha_fin', 'activa', 'creado_por_id',
    ];

    protected function casts(): array
    {
        return [
            'valor'        => 'decimal:2',
            'fecha_inicio' => 'date:Y-m-d',
            'fecha_fin'    => 'date:Y-m-d',
            'activa'       => 'boolean',
        ];
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

    public function tiendas()
    {
        return $this->belongsToMany(Tienda::class, 'promocion_tienda', 'promocion_id', 'tienda_id');
    }

    public function creadoPor()
    {
        return $this->belongsTo(Usuario::class, 'creado_por_id');
    }

    /** Hoy en Colombia: después de las 7 p. m. UTC ya es otro día allá. */
    public static function hoy(): string
    {
        return Carbon::now('America/Bogota')->toDateString();
    }

    /** Las que corren en esa fecha (hoy si no se dice), encendidas. */
    public function scopeVigentes(Builder $q, ?string $fecha = null): Builder
    {
        $fecha ??= self::hoy();
        return $q->where('activa', true)
            ->whereDate('fecha_inicio', '<=', $fecha)
            ->whereDate('fecha_fin', '>=', $fecha);
    }

    public function scopeEnTienda(Builder $q, int $tiendaId): Builder
    {
        return $q->whereHas('tiendas', fn ($t) => $t->where('tiendas.id', $tiendaId));
    }

    /** vigente | programada | terminada | apagada — para la lista. */
    public function estado(?string $hoy = null): string
    {
        $hoy ??= self::hoy();
        if (! $this->activa) return 'apagada';
        if ($this->fecha_fin->toDateString() < $hoy) return 'terminada';
        if ($this->fecha_inicio->toDateString() > $hoy) return 'programada';
        return 'vigente';
    }

    /** 1 = una opción o una tela; 0 = el producto entero. */
    public function especificidad(): int
    {
        return ($this->config_id || $this->variante_id) ? 1 : 0;
    }

    /**
     * ¿Se puede decir que esta línea se vendió con ella? Tiene que ser el
     * mismo producto, y la opción o la tela no pueden contradecirla. Si la
     * línea no trae opción no se rechaza: con dos opciones elegidas (1.40 y
     * con baúl) la orden guarda solo el texto, y la pantalla sí sabía cuáles.
     */
    public function leAplicaA(int $productoId, ?int $configId, ?int $varianteId): bool
    {
        if ((int) $this->producto_id !== $productoId) return false;
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

    /** Cuánto queda una unidad que valía $lista. Nunca menos de 0 ni más de $lista. */
    public function precioCon(float $lista): float
    {
        $valor = (float) $this->valor;
        $queda = match ($this->tipo) {
            'porcentaje' => $lista - round($lista * $valor / 100),
            'monto'      => $lista - $valor,
            'precio'     => min($lista, $valor),
            default      => $lista,
        };
        return max(0, min($lista, $queda));
    }

    /** "1.40", "Gris · Pana": de cuál versión es. null = el producto entero. */
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
}
