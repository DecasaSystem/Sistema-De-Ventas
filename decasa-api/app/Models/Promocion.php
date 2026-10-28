<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Unos productos (o versiones suyas) más baratos en unas tiendas, de una fecha
 * a otra. Ver las migraciones promociones_por_tienda y
 * promociones_varios_productos.
 *
 * La promoción son las reglas (cuánto, fechas, tiendas); qué se rebaja va en
 * `objetivos` (uno o varios productos, cada uno con todas sus versiones, solo
 * algunas o ninguna). Así la misma rebaja se pone a varios productos de una.
 *
 * El precio de lista no cambia: la pantalla de Nueva orden le aplica la
 * rebaja a la línea (como el descuento por producto) y la orden guarda el
 * precio ya rebajado más `promocion_id`.
 */
class Promocion extends Model
{
    protected $table = 'promociones';

    public const TIPOS = ['porcentaje', 'monto', 'precio'];

    // producto_id/config_id/variante_id: columnas de antes, con el primer
    // producto copiado. Lo que vale es `objetivos`.
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

    /** Lo que rebaja: una fila por producto o por versión. */
    public function objetivos()
    {
        return $this->hasMany(PromocionProducto::class, 'promocion_id')->orderBy('id');
    }

    public function tiendas()
    {
        return $this->belongsToMany(Tienda::class, 'promocion_tienda', 'promocion_id', 'tienda_id');
    }

    public function creadoPor()
    {
        return $this->belongsTo(Usuario::class, 'creado_por_id');
    }

    /** Lo que hay que cargar para mostrarla o calcular precios. */
    public const RELACIONES = [
        'objetivos.producto:id,nombre,categoria,precio_base,foto_url',
        'objetivos.config.opcion',
        'objetivos.variante',
        'tiendas:id,nombre',
    ];

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

    /** ¿Alguno de sus productos o versiones es el de esta línea? (Ver PromocionProducto::leAplicaA.) */
    public function leAplicaA(int $productoId, ?int $configId, ?int $varianteId): bool
    {
        return $this->objetivos->contains(fn (PromocionProducto $o) => $o->leAplicaA($productoId, $configId, $varianteId));
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
}
