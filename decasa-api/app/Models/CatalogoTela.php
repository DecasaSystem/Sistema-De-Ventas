<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una tela: proveedor + nombre + referencia + color.
 *
 * La referencia distingue telas del mismo nombre y color: LAYLA 01 CRUDO y
 * LAYLA 02 PERLA pueden ser las dos BEIGE y son telas distintas, con sus
 * metros aparte. Antes solo contaban proveedor, nombre y color, y la segunda
 * no se podía crear.
 */
class CatalogoTela extends Model
{
    protected $table = 'catalogo_telas';

    protected $fillable = ['marca', 'tipo', 'color', 'referencia', 'textura', 'foto_url', 'activo', 'metros_disponibles', 'metros_reservados'];

    protected $casts = ['activo' => 'boolean'];

    /**
     * El nombre con que la tela se elige al vender: "LAYLA 01 CRUDO".
     *
     * Las órdenes eligen proveedor → nombre → color y guardan el texto
     * "Proveedor · Nombre · Color". Con dos LAYLA en BEIGE ese texto no
     * alcanzaría para saber cuál, así que la referencia va pegada al nombre.
     * Si no hay referencia, o ya es el nombre (las telas del Excel, donde
     * son el mismo texto), o el nombre ya la trae, queda el nombre solo: esas
     * se siguen viendo como siempre.
     */
    public static function nombreVenta(?string $tipo, ?string $referencia): string
    {
        $tipo = trim((string) $tipo);
        $ref  = trim((string) $referencia);
        if ($ref === '') return $tipo;

        $t = mb_strtolower($tipo);
        $r = mb_strtolower($ref);
        if ($t === $r || str_contains($t, $r)) return $tipo;

        return "{$tipo} {$ref}";
    }

    public function getNombreVentaAttribute(): string
    {
        return self::nombreVenta($this->tipo, $this->referencia);
    }

    /**
     * La tela que nombra una orden (proveedor, nombre de venta, color).
     *
     * Primero la que se llama exactamente así. Si ninguna, por el nombre a
     * secas: las órdenes de antes guardaron "Arthometextil · LAYLA · BEIGE",
     * de cuando había una sola. Si ahora hay varias con ese nombre y color,
     * se queda con la que ya existía cuando se hizo la orden (la más antigua)
     * y no con la que se creó después.
     */
    public static function porNombreVenta(?string $marca, ?string $nombre, ?string $color): ?self
    {
        $marca  = trim((string) $marca);
        $nombre = trim((string) $nombre);
        $color  = trim((string) $color);
        if ($marca === '' || $nombre === '' || $color === '') return null;

        $candidatas = self::where('marca', $marca)->where('color', $color)
            ->where('activo', true)->orderBy('id')->get();

        $n = mb_strtolower($nombre);
        return $candidatas->first(fn ($t) => mb_strtolower($t->nombre_venta) === $n)
            ?? $candidatas->first(fn ($t) => mb_strtolower(trim($t->tipo)) === $n);
    }

    /**
     * Que la tela de una variante aparezca en Telas, sin repetirla.
     *
     * Al crear una variante tapizada se da de alta su tela con 0 metros. Antes
     * era un insertOrIgnore que se apoyaba en el índice único de proveedor +
     * nombre + color para no duplicar; sin ese índice (ahora la referencia
     * también cuenta) insertaría una copia cada vez. Si ya hay alguna con ese
     * proveedor, nombre y color —con la referencia que sea, activa o no—, no
     * se crea otra.
     */
    public static function asegurar(string $marca, string $tipo, string $color): void
    {
        $existe = self::where('marca', trim($marca))->where('tipo', trim($tipo))
            ->where('color', trim($color))->exists();

        if (! $existe) {
            self::create([
                'marca' => trim($marca), 'tipo' => trim($tipo), 'color' => trim($color),
                'activo' => true, 'metros_disponibles' => 0, 'metros_reservados' => 0,
            ]);
        }
    }

    /**
     * ¿Hay otra tela que sea esta misma? Proveedor, nombre, referencia y color
     * iguales (sin mayúsculas ni espacios de más, y sin referencia = vacía).
     */
    public static function igualA(string $marca, string $tipo, ?string $referencia, string $color, ?int $menos = null): ?self
    {
        $ref = mb_strtolower(trim((string) $referencia));

        return self::where('marca', trim($marca))
            ->where('tipo', trim($tipo))
            ->where('color', trim($color))
            ->when($menos, fn ($q) => $q->where('id', '!=', $menos))
            ->get()
            ->first(fn ($t) => mb_strtolower(trim((string) $t->referencia)) === $ref);
    }
}
