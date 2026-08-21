<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un proceso del taller: pelar, tapizar, lacar, lo que el taller necesite.
 *
 * Los mantiene el supervisor desde Producción, sin tocar código.
 */
class TipoProceso extends Model
{
    protected $table = 'tipos_proceso';

    protected $fillable = ['clave', 'nombre', 'descripcion', 'color', 'perfiles', 'orden', 'activo'];

    protected function casts(): array
    {
        return [
            'perfiles' => 'array',
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
    }

    /**
     * Trabajadores asignados a este proceso a dedo, aparte de los que le
     * llegan por su especialidad. Los dos caminos se suman.
     */
    public function trabajadores()
    {
        return $this->belongsToMany(Usuario::class, 'proceso_trabajadores', 'tipo_proceso_id', 'usuario_id');
    }

    /** Las claves que puede trabajar un perfil. */
    public static function clavesDePerfil(string $perfil): array
    {
        return static::where('activo', true)
            ->get()
            ->filter(fn ($t) => in_array($perfil, $t->perfiles ?? [], true))
            ->pluck('clave')
            ->values()
            ->all();
    }

    /**
     * Los perfiles que hacen un proceso.
     *
     * Se trae el modelo entero y no value('perfiles'): value() no pasa por los
     * casts y devolvería el JSON en crudo.
     */
    public static function perfilesDe(string $clave): array
    {
        return static::where('clave', $clave)->first()?->perfiles ?? [];
    }
}
