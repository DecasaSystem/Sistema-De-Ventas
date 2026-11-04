<?php

namespace App\Services;

use App\Models\Producto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Las categorías de inventario: renombrarlas y juntarlas.
 *
 * No hay tabla de categorías: cada producto lleva el texto en
 * `productos.categoria` y la lista de Inventario sale de juntar esos textos.
 * Por eso "crear" una categoría es escribir un nombre nuevo en un producto, y
 * hasta 2026-10-29 un error de dedo solo se arreglaba producto por producto.
 *
 * Renombrar cambia el texto en TODOS los productos de esa categoría (activos y
 * apagados) en una sola transacción, y anota el cambio en
 * `categorias_renombradas` para que los links ya mandados a clientes y los
 * agentes de chat sigan encontrando la categoría (ver la migración).
 *
 * Lo que no se toca a propósito: `orden_items.categoria_custom` (es la copia
 * que guardó cada ítem personalizado al venderse, historia de la orden) y
 * `fichas_tecnicas.categoria` (el cotizador tiene su propia lista fija).
 */
class CategoriasProducto
{
    /**
     * La categoría en minúsculas, sin tildes ni separadores: "sillas_comedor",
     * "Sillas comedor" y "sillas-comedor" son la misma. Es la misma comparación
     * del link público (`CatalogoPublicoController::normalizar`) y la de los
     * agentes; si cambia aquí, cambia allá.
     */
    public static function clave(?string $texto): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(Str::ascii((string) $texto)));
    }

    /**
     * Todas las categorías con cuántos productos tienen (activos y apagados).
     *
     * @return Collection<int, array{nombre:string, productos:int, activos:int}>
     */
    public static function listado(): Collection
    {
        return DB::table('productos')
            ->whereNotNull('categoria')->where('categoria', '<>', '')
            ->selectRaw('categoria, COUNT(*) AS productos, SUM(CASE WHEN activo THEN 1 ELSE 0 END) AS activos')
            ->groupBy('categoria')
            ->orderBy('categoria')
            ->get()
            ->map(fn ($r) => [
                'nombre'    => (string) $r->categoria,
                'productos' => (int) $r->productos,
                'activos'   => (int) $r->activos,
            ])->values();
    }

    /**
     * La otra categoría que ya se llama como `$a` (sin mirar tildes,
     * mayúsculas ni separadores). Si `$a` es la misma `$de` escrita distinto
     * ("sofas" → "Sofás") no hay otra: es solo corregir cómo se escribe.
     */
    public static function otraQueSeLlamaAsi(string $de, string $a): ?string
    {
        $claveA = self::clave($a);
        if ($claveA === self::clave($de)) {
            return null;
        }

        return self::listado()
            ->first(fn ($c) => self::clave($c['nombre']) === $claveA)['nombre'] ?? null;
    }

    /**
     * Pasa todos los productos de `$de` a `$a`.
     *
     * Si ya existe otra categoría con ese nombre hay que pedirlo con `$juntar`:
     * los productos de las dos quedan en una sola, escrita como `$a` (así quien
     * junta "Sofa" con "sofas" puede dejarla de una vez como "Sofás").
     *
     * @return array{de:string, a:string, productos:int, juntada_con:?string}
     */
    public static function renombrar(string $de, string $a, bool $juntar, ?int $usuarioId): array
    {
        $de = trim($de);
        $a  = trim(preg_replace('/\s+/', ' ', $a));

        if ($a === '' || self::clave($a) === '') {
            throw ValidationException::withMessages(['a' => 'Escribe el nombre nuevo de la categoría.']);
        }
        if (mb_strlen($a) > 80) {
            throw ValidationException::withMessages(['a' => 'El nombre puede tener hasta 80 letras.']);
        }
        if ($a === $de) {
            throw ValidationException::withMessages(['a' => 'Es el mismo nombre que ya tiene.']);
        }
        if (! Producto::where('categoria', $de)->exists()) {
            throw ValidationException::withMessages(['de' => "No hay productos en la categoría \"{$de}\"."]);
        }

        $otra = self::otraQueSeLlamaAsi($de, $a);
        if ($otra !== null && ! $juntar) {
            // El controlador lo pregunta antes con un 409; esto es por si
            // alguien llama aquí sin preguntar.
            throw ValidationException::withMessages(['a' => "Ya existe la categoría \"{$otra}\"."]);
        }

        return DB::transaction(function () use ($de, $a, $otra, $usuarioId) {
            $deLaPrimera = Producto::where('categoria', $de)->update(['categoria' => $a]);
            $deLaOtra    = 0;
            if ($otra !== null && $otra !== $a) {
                $deLaOtra = Producto::where('categoria', $otra)->update(['categoria' => $a]);
            }

            self::anotar($de, $a, $deLaPrimera, $otra !== null, $usuarioId);
            if ($deLaOtra > 0) {
                self::anotar($otra, $a, $deLaOtra, true, $usuarioId);
            }

            return [
                'de'          => $de,
                'a'           => $a,
                'productos'   => $deLaPrimera + $deLaOtra,
                'juntada_con' => $otra,
            ];
        });
    }

    /**
     * Cómo se llama hoy la categoría que antes se llamaba así. Sigue la cadena
     * (Truf → Throw → Throws). Null si nunca se renombró.
     */
    public static function actualDe(string $nombreOClave): ?string
    {
        if (! Schema::hasTable('categorias_renombradas')) {
            return null;
        }

        $fila = DB::table('categorias_renombradas')
            ->where('clave_vieja', self::clave($nombreOClave))
            ->orderByDesc('id')
            ->first(['nombre_nuevo']);

        return $fila?->nombre_nuevo;
    }

    /**
     * Anota el cambio y endereza los renombres de antes: lo que apuntaba a
     * `$de` ahora apunta a `$a`, así un link de hace dos renombres sigue
     * abriendo. Si un nombre vuelve a llamarse como antes, su renglón sobra.
     */
    private static function anotar(string $de, string $a, int $productos, bool $fueJuntar, ?int $usuarioId): void
    {
        if (! Schema::hasTable('categorias_renombradas')) {
            return;
        }

        $claveDe = self::clave($de);
        $claveA  = self::clave($a);

        $viejos = DB::table('categorias_renombradas')->get(['id', 'clave_vieja', 'nombre_nuevo']);
        foreach ($viejos as $v) {
            if (self::clave($v->nombre_nuevo) !== $claveDe) {
                continue;
            }
            if ($v->clave_vieja === $claveA) {
                DB::table('categorias_renombradas')->where('id', $v->id)->delete();
            } else {
                DB::table('categorias_renombradas')->where('id', $v->id)
                    ->update(['nombre_nuevo' => $a, 'updated_at' => now()]);
            }
        }

        // "sofas" → "Sofás" no cambia el link ni lo que entiende el bot, pero
        // queda anotado igual: es la historia de quién cambió qué.
        DB::table('categorias_renombradas')->insert([
            'nombre_viejo' => mb_substr($de, 0, 80),
            'clave_vieja'  => $claveDe,
            'nombre_nuevo' => $a,
            'productos'    => $productos,
            'fue_juntar'   => $fueJuntar,
            'usuario_id'   => $usuarioId,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }
}
