<?php

namespace App\Http\Controllers;

use App\Models\FichaTecnica;
use App\Models\FichaTecnicaItem;
use App\Models\Material;
use App\Services\Costos\FichaRetriever;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FichaTecnicaController extends Controller
{
    private const PRODUCTO = 'producto:id,nombre,categoria,precio_base,foto_url';

    public function index(Request $request)
    {
        // Con el producto vinculado, para comparar costo contra precio de venta
        $query = FichaTecnica::query()->from('fichas_tecnicas as ft')
            ->leftJoin('productos as p', 'p.id', '=', 'ft.producto_id');

        if ($search = $request->query('search')) {
            $term = '%' . mb_strtolower($search) . '%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(ft.nombre) LIKE ?',    [$term])
                  ->orWhereRaw('LOWER(ft.categoria) LIKE ?', [$term]);
            });
        }

        if ($categoria = $request->query('categoria')) {
            $query->where('ft.categoria', $categoria);
        }

        $fichas = $query
            ->orderBy('ft.categoria')
            ->orderBy('ft.nombre')
            ->get([
                'ft.id', 'ft.nombre', 'ft.categoria', 'ft.costo_materiales', 'ft.costo_mano_obra',
                'ft.costo_total', 'ft.foto_url', 'ft.producto_id',
                'p.nombre as producto_nombre', 'p.precio_base as producto_precio',
            ]);

        // Lo que hay que revisar en cada ficha: ítems sin precio y materiales que no
        // están en el catálogo (no se actualizan cuando cambia el precio del material).
        $revisar = DB::table('ficha_tecnica_items')
            ->whereIn('ficha_tecnica_id', $fichas->pluck('id'))
            ->groupBy('ficha_tecnica_id')
            ->selectRaw('ficha_tecnica_id,
                SUM(CASE WHEN precio_unitario <= 0 OR TRIM(descripcion) = \'\' THEN 1 ELSE 0 END) AS sin_precio,
                SUM(CASE WHEN es_mano_obra = 0 AND material_id IS NULL AND TRIM(descripcion) <> \'\' THEN 1 ELSE 0 END) AS fuera_catalogo')
            ->get()
            ->keyBy('ficha_tecnica_id');

        $fichas->each(function ($f) use ($revisar) {
            $r = $revisar->get($f->id);
            $f->sin_precio     = (int) ($r->sin_precio ?? 0);
            $f->fuera_catalogo = (int) ($r->fuera_catalogo ?? 0);
        });

        $categorias = FichaTecnica::distinct()->orderBy('categoria')->pluck('categoria');

        return response()->json([
            'fichas'     => $fichas,
            'categorias' => $categorias,
            'total'      => $fichas->count(),
        ]);
    }

    public function show(FichaTecnica $fichaTecnica)
    {
        return response()->json($fichaTecnica->load(['items', self::PRODUCTO]));
    }

    /** PATCH /fichas-tecnicas/{id}/producto — vincula (o desvincula, con null) el producto del catálogo. */
    public function vincularProducto(Request $request, FichaTecnica $fichaTecnica)
    {
        $data = $request->validate([
            'producto_id' => 'present|nullable|integer|exists:productos,id',
        ]);

        $fichaTecnica->update(['producto_id' => $data['producto_id']]);

        return response()->json($fichaTecnica->fresh()->load(self::PRODUCTO));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'                  => 'required|string|max:255',
            'categoria'               => 'required|string|max:255',
            'foto_url'                => 'nullable|string|max:500',
            'items'                   => 'required|array|min:1',
            'items.*.seccion'         => 'nullable|string',
            'items.*.descripcion'     => 'required|string',
            'items.*.cantidad'        => 'required|numeric|min:0',
            'items.*.unidad'          => 'nullable|string',
            'items.*.precio_unitario' => 'required|numeric|min:0',
            'items.*.subtotal'        => 'nullable|numeric|min:0',
            'items.*.es_mano_obra'    => 'boolean',
            'items.*.material_id'     => 'nullable|integer|exists:materiales,id',
            // La mano de obra que sale de una tarifa queda vinculada a ella, para que al
            // cambiar el incentivo/hora en Tarifas la ficha se recalcule sola.
            'items.*.tarifa_proceso_id' => 'nullable|integer|exists:tarifas_proceso,id',
        ]);

        $ficha = DB::transaction(function () use ($data) {
            $ficha = FichaTecnica::create([
                'nombre'     => $data['nombre'],
                'categoria'  => $data['categoria'],
                'foto_url'   => $data['foto_url'] ?? null,
                'ruta_excel' => null,
            ]);

            $porNombre = $this->materialesPorNombre($data['items']);

            foreach ($data['items'] as $orden => $item) {
                $esManoObra = (bool) ($item['es_mano_obra'] ?? false);
                FichaTecnicaItem::create([
                    'ficha_tecnica_id'  => $ficha->id,
                    'seccion'           => $item['seccion'] ?? null,
                    'descripcion'       => trim($item['descripcion']),
                    'cantidad'          => $item['cantidad'],
                    'unidad'            => $item['unidad'] ?? null,
                    'precio_unitario'   => $item['precio_unitario'],
                    // El subtotal lo calcula el servidor, no se confía en el que manda el cliente
                    'subtotal'          => round($item['cantidad'] * $item['precio_unitario'], 2),
                    'es_mano_obra'      => $esManoObra,
                    'material_id'       => $esManoObra ? null : $this->materialDe($item, $porNombre),
                    'tarifa_proceso_id' => $esManoObra ? ($item['tarifa_proceso_id'] ?? null) : null,
                    'orden'             => $orden,
                ]);
            }

            FichaTecnica::recalcularTotales($ficha->id);

            return $ficha;
        });

        $this->indexarDespues($ficha->id);

        return response()->json($ficha->fresh()->load(['items', self::PRODUCTO]), 201);
    }

    public function materialesSugeridos(Request $request)
    {
        $search = $request->query('search', '');

        // Consultar desde el catálogo maestro de materiales
        $materiales = Material::when($search, fn($q) => $q->whereRaw('LOWER(nombre) LIKE ?', ['%' . mb_strtolower($search) . '%']))
            ->orderBy('nombre')
            ->limit(8)
            ->get()
            ->map(fn($m) => [
                'id'             => $m->id,
                'descripcion'    => $m->nombre,
                'unidad'         => $m->unidad,
                'precio_promedio'=> $m->precio_unitario,
                'usos'           => 1,
            ]);

        return response()->json($materiales);
    }

    public function updateItems(Request $request, FichaTecnica $fichaTecnica)
    {
        $data = $request->validate([
            'nombre'                  => 'sometimes|string|max:255',
            'categoria'               => 'sometimes|string|max:255',
            'foto_url'                => 'sometimes|nullable|string|max:500',
            'items'                   => 'present|array',
            // Sin id = ítem nuevo; con id = se actualiza
            'items.*.id'              => 'nullable|integer',
            'items.*.cantidad'        => 'required|numeric|min:0',
            'items.*.precio_unitario' => 'required|numeric|min:0',
            'items.*.subtotal'        => 'nullable|numeric|min:0',
            // "Cambiar material" reemplaza el nombre y la unidad del ítem, no solo el precio
            'items.*.descripcion'     => 'required_without:items.*.id|string|max:255',
            'items.*.unidad'          => 'sometimes|nullable|string|max:100',
            'items.*.seccion'         => 'sometimes|nullable|string|max:255',
            'items.*.es_mano_obra'    => 'sometimes|boolean',
            'items.*.material_id'     => 'nullable|integer|exists:materiales,id',
            'items.*.tarifa_proceso_id' => 'nullable|integer|exists:tarifas_proceso,id',
            'eliminar'                => 'sometimes|array',
            'eliminar.*'              => 'integer',
        ]);

        // Lo que el cotizador indexa: nombre, categoría y secciones (ver FichaRetriever::textoDeFicha)
        $huella = fn () => [
            $fichaTecnica->fresh()->only(['nombre', 'categoria']),
            FichaTecnicaItem::where('ficha_tecnica_id', $fichaTecnica->id)->distinct()->orderBy('seccion')->pluck('seccion')->all(),
        ];
        $huellaAntes = $huella();

        DB::transaction(function () use ($data, $fichaTecnica) {
            $camposActualizar = [];
            if (!empty($data['nombre']))    $camposActualizar['nombre']    = mb_strtoupper(trim($data['nombre']));
            if (!empty($data['categoria'])) $camposActualizar['categoria'] = mb_strtoupper(trim($data['categoria']));
            if (array_key_exists('foto_url', $data)) $camposActualizar['foto_url'] = $data['foto_url'];
            if (!empty($camposActualizar)) $fichaTecnica->update($camposActualizar);

            if (!empty($data['eliminar'])) {
                FichaTecnicaItem::where('ficha_tecnica_id', $fichaTecnica->id)
                    ->whereIn('id', $data['eliminar'])
                    ->delete();
            }

            $existentes     = FichaTecnicaItem::where('ficha_tecnica_id', $fichaTecnica->id)->get()->keyBy('id');
            $porNombre      = Material::idsPorNombre(array_merge(
                array_column($data['items'], 'descripcion'),
                $existentes->pluck('descripcion')->all(),
            ));
            $siguienteOrden = (int) $existentes->max('orden') + 1;

            foreach ($data['items'] as $itemData) {
                $actual = ! empty($itemData['id']) ? $existentes->get($itemData['id']) : null;
                // Un id que no es de esta ficha (o que se acaba de eliminar) no se toca
                if (! empty($itemData['id']) && ! $actual) continue;

                $campos = [
                    'cantidad'        => $itemData['cantidad'],
                    'precio_unitario' => $itemData['precio_unitario'],
                    'subtotal'        => round($itemData['cantidad'] * $itemData['precio_unitario'], 2),
                ];
                if (array_key_exists('descripcion', $itemData)) $campos['descripcion'] = trim($itemData['descripcion']);
                if (array_key_exists('unidad', $itemData))      $campos['unidad']      = $itemData['unidad'];
                if (array_key_exists('seccion', $itemData))     $campos['seccion']     = $itemData['seccion'] ? trim($itemData['seccion']) : null;

                if (! $actual) {
                    $esManoObra = (bool) ($itemData['es_mano_obra'] ?? false);
                    FichaTecnicaItem::create($campos + [
                        'ficha_tecnica_id'  => $fichaTecnica->id,
                        'es_mano_obra'      => $esManoObra,
                        'material_id'       => $esManoObra ? null : $this->materialDe($itemData, $porNombre),
                        'tarifa_proceso_id' => $esManoObra ? ($itemData['tarifa_proceso_id'] ?? null) : null,
                        'orden'             => $siguienteOrden++,
                    ]);
                    continue;
                }

                // Si cambió qué material es, se vuelve a enlazar con el catálogo
                if (! $actual->es_mano_obra && (array_key_exists('material_id', $itemData) || isset($campos['descripcion']))) {
                    $campos['material_id'] = $this->materialDe($itemData + ['descripcion' => $actual->descripcion], $porNombre);
                }

                $actual->update($campos);
            }

            if (! FichaTecnicaItem::where('ficha_tecnica_id', $fichaTecnica->id)->exists()) {
                throw ValidationException::withMessages(['items' => 'La ficha debe quedar con al menos un ítem.']);
            }

            FichaTecnica::recalcularTotales($fichaTecnica->id);
        });

        if ($huella() !== $huellaAntes) {
            $this->indexarDespues($fichaTecnica->id);
        }

        return response()->json($fichaTecnica->fresh()->load(['items', self::PRODUCTO]));
    }

    /** DELETE /fichas-tecnicas/{id} — los ítems se van en cascada. */
    public function destroy(FichaTecnica $fichaTecnica)
    {
        $fichaTecnica->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * POST /fichas-tecnicas/{id}/duplicar — copia la ficha con sus ítems (incluido el
     * vínculo con materiales y tarifas), para armar una variante sin empezar de cero.
     * El producto no se copia: una variante suele ser otro producto del catálogo.
     */
    public function duplicar(Request $request, FichaTecnica $fichaTecnica)
    {
        $data = $request->validate(['nombre' => 'nullable|string|max:255']);

        $copia = DB::transaction(function () use ($data, $fichaTecnica) {
            $copia = FichaTecnica::create([
                'nombre'    => mb_strtoupper(trim($data['nombre'] ?? '') ?: 'COPIA DE ' . $fichaTecnica->nombre),
                'categoria' => $fichaTecnica->categoria,
                'foto_url'  => $fichaTecnica->foto_url,
            ]);

            foreach ($fichaTecnica->items as $item) {
                FichaTecnicaItem::create($item->only([
                    'seccion', 'descripcion', 'cantidad', 'unidad', 'precio_unitario',
                    'subtotal', 'es_mano_obra', 'material_id', 'tarifa_proceso_id', 'orden',
                ]) + ['ficha_tecnica_id' => $copia->id]);
            }

            FichaTecnica::recalcularTotales($copia->id);

            return $copia;
        });

        $this->indexarDespues($copia->id);

        return response()->json($copia->fresh()->load(['items', self::PRODUCTO]), 201);
    }

    /** Materiales del catálogo cuyo nombre coincide con la descripción de algún ítem. */
    private function materialesPorNombre(array $items): array
    {
        return Material::idsPorNombre(array_column($items, 'descripcion'));
    }

    /**
     * El material del catálogo de un ítem: el que eligieron en el buscador, o si lo
     * escribieron a mano, el que se llame igual. null = fuera del catálogo.
     */
    private function materialDe(array $item, array $porNombre): ?int
    {
        if (! empty($item['material_id'])) return (int) $item['material_id'];

        return $porNombre[mb_strtolower(trim($item['descripcion'] ?? ''))] ?? null;
    }

    /** Indexa la ficha para el cotizador después de responder, para no hacer esperar al usuario. */
    private function indexarDespues(int $fichaId): void
    {
        dispatch(fn () => app(FichaRetriever::class)->indexarFicha($fichaId))->afterResponse();
    }
}
