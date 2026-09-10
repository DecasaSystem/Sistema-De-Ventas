<?php

namespace App\Http\Controllers;

use App\Models\Catalogo;
use App\Models\CatalogoPagina;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Administración de los catálogos visuales (Gestión → Catálogos).
 *
 * Las imágenes ya vienen subidas a Cloudinary por /api/upload/foto; aquí solo
 * se guardan sus URLs y su orden. Todo esto es solo para supervisores; la
 * parte pública (lo que ve el cliente) está en CatalogoPublicoController.
 */
class CatalogoVisualController extends Controller
{
    /** GET /api/catalogos-visuales — lista para el panel de administración. */
    public function index()
    {
        $catalogos = Catalogo::withCount('paginas')
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get();

        return response()->json($catalogos->map(fn (Catalogo $c) => [
            'id'            => $c->id,
            'nombre'        => $c->nombre,
            'slug'          => $c->slug,
            'descripcion'   => $c->descripcion,
            'activo'        => $c->activo,
            'orden'         => $c->orden,
            'paginas_count' => $c->paginas_count,
            'portada_url'   => $c->portadaResuelta(),
        ]));
    }

    /** GET /api/catalogos-visuales/{id} — un catálogo con todas sus páginas. */
    public function show(int $id)
    {
        $catalogo = Catalogo::with('paginas')->findOrFail($id);

        return response()->json($this->conPaginas($catalogo));
    }

    /** POST /api/catalogos-visuales */
    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'      => 'required|string|max:120',
            'descripcion' => 'nullable|string|max:300',
        ]);

        $catalogo = Catalogo::create([
            'nombre'      => trim($data['nombre']),
            'slug'        => Catalogo::slugLibre($data['nombre']),
            'descripcion' => $data['descripcion'] ?? null,
            'orden'       => (int) Catalogo::max('orden') + 10,
        ]);

        return response()->json($this->conPaginas($catalogo->load('paginas')), 201);
    }

    /** PATCH /api/catalogos-visuales/{id} */
    public function update(Request $request, int $id)
    {
        $catalogo = Catalogo::findOrFail($id);

        $data = $request->validate([
            'nombre'      => 'sometimes|required|string|max:120',
            'descripcion' => 'sometimes|nullable|string|max:300',
            'activo'      => 'sometimes|boolean',
            'orden'       => 'sometimes|integer|min:0|max:99999',
            // Debe ser una de sus propias páginas, o null para volver a "la primera".
            'portada_url' => 'sometimes|nullable|string|max:500',
        ]);

        if (array_key_exists('portada_url', $data) && $data['portada_url']) {
            $esSuya = $catalogo->paginas()->where('imagen_url', $data['portada_url'])->exists();
            if (! $esSuya) {
                return response()->json(['message' => 'La portada tiene que ser una página del catálogo.'], 422);
            }
        }

        $catalogo->fill($data)->save();

        return response()->json($this->conPaginas($catalogo->load('paginas')));
    }

    /** DELETE /api/catalogos-visuales/{id} */
    public function destroy(int $id)
    {
        Catalogo::findOrFail($id)->delete();   // cascade borra las páginas

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/catalogos-visuales/{id}/paginas
     * Body: { imagenes: ["https://res.cloudinary.com/...", ...] }
     * Agrega páginas al final, en el orden en que llegan.
     */
    public function agregarPaginas(Request $request, int $id)
    {
        $catalogo = Catalogo::findOrFail($id);

        $data = $request->validate([
            'imagenes'   => 'required|array|min:1|max:60',
            'imagenes.*' => 'required|url|max:500',
        ]);

        $desde = (int) $catalogo->paginas()->max('orden');

        DB::transaction(function () use ($catalogo, $data, &$desde) {
            foreach ($data['imagenes'] as $url) {
                $desde += 10;
                $catalogo->paginas()->create(['imagen_url' => $url, 'orden' => $desde]);
            }
        });

        return response()->json($this->conPaginas($catalogo->load('paginas')));
    }

    /**
     * PATCH /api/catalogos-visuales/{id}/paginas/orden
     * Body: { orden: [pagina_id, pagina_id, ...] } — el nuevo orden completo.
     */
    public function reordenarPaginas(Request $request, int $id)
    {
        $catalogo = Catalogo::findOrFail($id);

        $data = $request->validate([
            'orden'   => 'required|array|min:1',
            'orden.*' => 'integer',
        ]);

        $suyas = $catalogo->paginas()->pluck('id')->all();
        if (array_diff($data['orden'], $suyas) || array_diff($suyas, $data['orden'])) {
            return response()->json(['message' => 'La lista tiene que traer exactamente las páginas de este catálogo.'], 422);
        }

        DB::transaction(function () use ($data) {
            foreach ($data['orden'] as $i => $paginaId) {
                CatalogoPagina::where('id', $paginaId)->update(['orden' => ($i + 1) * 10]);
            }
        });

        return response()->json($this->conPaginas($catalogo->load('paginas')));
    }

    /** PATCH /api/catalogos-visuales/{id}/paginas/{pid} — nota de una página. */
    public function actualizarPagina(Request $request, int $id, int $pid)
    {
        $pagina = CatalogoPagina::where('catalogo_id', $id)->findOrFail($pid);

        $data = $request->validate([
            'nota' => 'nullable|string|max:200',
        ]);

        $pagina->update(['nota' => $data['nota'] ?? null]);

        return response()->json($this->conPaginas($pagina->catalogo->load('paginas')));
    }

    /** DELETE /api/catalogos-visuales/{id}/paginas/{pid} */
    public function eliminarPagina(int $id, int $pid)
    {
        $pagina   = CatalogoPagina::where('catalogo_id', $id)->findOrFail($pid);
        $catalogo = $pagina->catalogo;

        // Si era la portada elegida a mano, se vuelve a "la primera".
        if ($catalogo->portada_url === $pagina->imagen_url) {
            $catalogo->update(['portada_url' => null]);
        }
        $pagina->delete();

        return response()->json($this->conPaginas($catalogo->load('paginas')));
    }

    /** Forma común de devolver un catálogo con sus páginas ya ordenadas. */
    private function conPaginas(Catalogo $catalogo): array
    {
        return [
            'id'          => $catalogo->id,
            'nombre'      => $catalogo->nombre,
            'slug'        => $catalogo->slug,
            'descripcion' => $catalogo->descripcion,
            'activo'      => $catalogo->activo,
            'orden'       => $catalogo->orden,
            'portada_url' => $catalogo->portada_url,
            'paginas'     => $catalogo->paginas->map(fn (CatalogoPagina $p) => [
                'id'         => $p->id,
                'imagen_url' => $p->imagen_url,
                'nota'       => $p->nota,
                'orden'      => $p->orden,
            ])->values(),
        ];
    }
}
