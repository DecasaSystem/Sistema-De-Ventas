<?php

namespace App\Http\Controllers;

use App\Models\Catalogo;
use App\Models\CatalogoPagina;
use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * El catálogo que se le manda a un cliente por WhatsApp.
 *
 * Es PÚBLICO: no pide contraseña, porque el cliente no tiene cuenta. Por eso
 * sale de aquí solo lo que uno le mostraría a un cliente parado en la tienda
 * —foto, nombre, precio, medidas— y nada de lo que hay detrás: ni existencias,
 * ni de qué tienda sale, ni costos, ni quién lo vende.
 *
 * Cada link es de UNA sección. El que pregunta por relojes ve relojes, y no
 * tiene por dónde pasearse al resto del inventario.
 */
class CatalogoPublicoController extends Controller
{
    /**
     * Las categorías tal como están guardadas ("sillas_comedor", "Reloj") no
     * sirven de dirección web. Se comparan por su versión en minúsculas y sin
     * separadores, así "sillas-comedor", "sillas_comedor" y "Sillas Comedor"
     * llevan todas al mismo sitio.
     */
    private static function normalizar(?string $texto): string
    {
        $sinTildes = Str::ascii((string) $texto);

        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($sinTildes));
    }

    /** GET /api/catalogo/{seccion} — público */
    public function seccion(Request $request, string $seccion)
    {
        $buscada = self::normalizar($seccion);
        if ($buscada === '') {
            return response()->json(['message' => 'Sección no encontrada.'], 404);
        }

        $productos = Producto::where('activo', true)
            ->whereNotNull('categoria')
            ->where('categoria', '<>', '')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'categoria', 'precio_base', 'foto_url', 'foto_url_2',
                   'medidas', 'material', 'descripcion']);

        $deLaSeccion = $productos->filter(
            fn (Producto $p) => self::normalizar($p->categoria) === $buscada
        )->values();

        if ($deLaSeccion->isEmpty()) {
            return response()->json(['message' => 'Sección no encontrada.'], 404);
        }

        return response()->json([
            // El nombre bonito sale del propio dato, no de una lista aparte.
            'seccion'   => self::titulo($deLaSeccion->first()->categoria),
            'productos' => $deLaSeccion->map(fn (Producto $p) => [
                'id'          => $p->id,
                'nombre'      => $p->nombre,
                'precio'      => (float) $p->precio_base,
                'foto_url'    => $p->foto_url,
                'foto_url_2'  => $p->foto_url_2,
                'medidas'     => $p->medidas,
                'material'    => $p->material,
                'descripcion' => $p->descripcion,
            ])->values(),
        ]);
    }

    /**
     * GET /api/c — público
     * La portada: todas las categorías con catálogo visual activo.
     */
    public function visuales()
    {
        $catalogos = Catalogo::where('activo', true)
            ->withCount('paginas')
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get()
            ->filter(fn (Catalogo $c) => $c->paginas_count > 0)
            ->values();

        return response()->json([
            'catalogos' => $catalogos->map(fn (Catalogo $c) => [
                'nombre'      => $c->nombre,
                'slug'        => $c->slug,
                'descripcion' => $c->descripcion,
                'paginas'     => $c->paginas_count,
                'portada_url' => $c->portadaResuelta(),
            ])->values(),
        ]);
    }

    /**
     * GET /api/c/{slug} — público
     * Un catálogo visual con sus páginas en orden.
     */
    public function visual(string $slug)
    {
        $catalogo = Catalogo::where('slug', $slug)->where('activo', true)->first();

        if (! $catalogo) {
            return response()->json(['message' => 'Catálogo no encontrado.'], 404);
        }

        $paginas = $catalogo->paginas()->get(['imagen_url', 'nota']);

        if ($paginas->isEmpty()) {
            return response()->json(['message' => 'Catálogo no encontrado.'], 404);
        }

        // Un catálogo abre con su portada, como una revista: la página marcada
        // con la estrella va de primera y las demás siguen en su orden. Antes
        // la portada solo salía en la grilla de /c y el visor arrancaba en la
        // primera página del orden, que no tenía por qué ser ella.
        if ($catalogo->portada_url) {
            [$portada, $resto] = $paginas->partition(fn ($p) => $p->imagen_url === $catalogo->portada_url);
            if ($portada->isNotEmpty()) {
                $paginas = $portada->take(1)->concat($resto)->concat($portada->slice(1));
            }
        }

        return response()->json([
            'nombre'      => $catalogo->nombre,
            'slug'        => $catalogo->slug,
            'descripcion' => $catalogo->descripcion,
            'paginas'     => $paginas->map(fn (CatalogoPagina $p) => [
                'imagen_url' => $p->imagen_url,
                'nota'       => $p->nota,
            ])->values(),
        ]);
    }

    /** "sillas_comedor" -> "Sillas comedor" */
    private static function titulo(?string $categoria): string
    {
        $limpio = trim(str_replace(['_', '-'], ' ', (string) $categoria));

        return $limpio === '' ? 'Catálogo' : Str::ucfirst(mb_strtolower($limpio));
    }
}
