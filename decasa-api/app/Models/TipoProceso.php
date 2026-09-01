<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Un proceso del taller: pelar, tapizar, lacar, lo que el taller necesite.
 *
 * Los mantiene el supervisor desde Producción, sin tocar código.
 */
class TipoProceso extends Model
{
    protected $table = 'tipos_proceso';

    protected $fillable = ['clave', 'nombre', 'descripcion', 'color', 'orden', 'activo'];

    protected function casts(): array
    {
        return [
            'activo'   => 'boolean',
            'orden'    => 'integer',
        ];
    }

    /** Colores que el front sabe pintar. */
    public const COLORES = [
        'orange', 'teal', 'indigo', 'yellow', 'purple', 'pink',
        'rose', 'stone', 'blue', 'green', 'red', 'slate',
    ];

    /**
     * Las dos líneas de trabajo del taller.
     *
     * Una pieza es de una o de la otra —restaurar el mueble del cliente, o
     * hacer uno nuevo—, y sale sola de `orden_items.es_restauracion`. Una
     * PERSONA en cambio puede estar en las dos, que es lo que dice `AMBAS`.
     */
    public const LINEA_NORMAL       = 'normal';
    public const LINEA_RESTAURACION = 'restauracion';
    public const LINEA_AMBAS        = 'ambas';

    public const LINEAS = [self::LINEA_NORMAL, self::LINEA_RESTAURACION];

    /** La línea que le toca a una pieza. */
    public static function lineaDe(bool $esRestauracion): string
    {
        return $esRestauracion ? self::LINEA_RESTAURACION : self::LINEA_NORMAL;
    }

    /**
     * clave → nombre, para no consultar la tabla por cada paso de cada lista.
     * Se rearma sola en la siguiente petición, así que un cambio se ve al momento.
     */
    private static ?array $cacheNombres = null;

    public static function nombreDe(string $clave): string
    {
        if (self::$cacheNombres === null) {
            self::$cacheNombres = static::pluck('nombre', 'clave')->all();
        }
        // Un paso viejo cuyo tipo ya no está en el catálogo se sigue leyendo:
        // el trabajo se hizo, aunque el proceso ya no se ofrezca.
        return self::$cacheNombres[$clave] ?? $clave;
    }

    public static function olvidarCache(): void
    {
        self::$cacheNombres = null;
        self::$cacheSepara  = null;
    }

    /**
     * ¿El taller lleva las restauraciones aparte de lo nuevo?
     *
     * Con el interruptor apagado la línea no decide nada: quien está en un
     * proceso lo trabaja entero, como siempre. Encendido, cada quien ve solo
     * la línea que le tocó (y quien quedó en 'ambas' las sigue viendo las dos).
     *
     * Es una casilla y no una estructura aparte a propósito: esto puede
     * cambiar, y volver atrás tiene que costar un clic.
     */
    private static ?bool $cacheSepara = null;

    public static function separaRestauraciones(): bool
    {
        return self::$cacheSepara ??= (bool) DB::table('configuracion')
            ->where('clave', 'produccion_separa_restauraciones')
            ->value('valor');
    }

    public static function definirSeparacion(bool $activo): void
    {
        DB::table('configuracion')->updateOrInsert(
            ['clave' => 'produccion_separa_restauraciones'],
            ['valor' => $activo ? '1' : '0'],
        );
        self::$cacheSepara = $activo;
    }

    /**
     * Trabajadores asignados a este proceso, con la línea que lleva cada uno.
     */
    public function trabajadores()
    {
        return $this->belongsToMany(Usuario::class, 'proceso_trabajadores', 'tipo_proceso_id', 'usuario_id')
            ->withPivot('linea');
    }

}
