<?php

namespace App\Http\Controllers;

use App\Models\FichaTecnica;
use App\Models\FichaTecnicaItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class FichaTecnicaController extends Controller
{
    public function index(Request $request)
    {
        $query = FichaTecnica::query();

        if ($search = $request->query('search')) {
            $term = '%' . mb_strtolower($search) . '%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(nombre) LIKE ?',    [$term])
                  ->orWhereRaw('LOWER(categoria) LIKE ?', [$term]);
            });
        }

        if ($categoria = $request->query('categoria')) {
            $query->where('categoria', $categoria);
        }

        $fichas = $query
            ->orderBy('categoria')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'categoria', 'costo_materiales', 'costo_mano_obra', 'costo_total', 'foto_url']);

        $categorias = FichaTecnica::distinct()->orderBy('categoria')->pluck('categoria');

        return response()->json([
            'fichas'     => $fichas,
            'categorias' => $categorias,
            'total'      => $fichas->count(),
        ]);
    }

    public function show(FichaTecnica $fichaTecnica)
    {
        $fichaTecnica->load('items');
        return response()->json($fichaTecnica);
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
            'items.*.subtotal'        => 'required|numeric|min:0',
            'items.*.es_mano_obra'    => 'boolean',
        ]);

        $itemsCol        = collect($data['items']);
        $costoMateriales = $itemsCol->where('es_mano_obra', false)->sum('subtotal');
        $costoManoObra   = $itemsCol->where('es_mano_obra', true)->sum('subtotal');

        $ficha = FichaTecnica::create([
            'nombre'           => $data['nombre'],
            'categoria'        => $data['categoria'],
            'foto_url'         => $data['foto_url'] ?? null,
            'costo_materiales' => $costoMateriales,
            'costo_mano_obra'  => $costoManoObra,
            'costo_total'      => $costoMateriales + $costoManoObra,
            'ruta_excel'       => null,
        ]);

        foreach ($data['items'] as $orden => $item) {
            FichaTecnicaItem::create([
                'ficha_tecnica_id' => $ficha->id,
                'seccion'          => $item['seccion'] ?? null,
                'descripcion'      => $item['descripcion'],
                'cantidad'         => $item['cantidad'],
                'unidad'           => $item['unidad'] ?? null,
                'precio_unitario'  => $item['precio_unitario'],
                'subtotal'         => $item['subtotal'],
                'es_mano_obra'     => $item['es_mano_obra'] ?? false,
                'orden'            => $orden,
            ]);
        }

        return response()->json($ficha->load('items'), 201);
    }

    public function materialesSugeridos(Request $request)
    {
        $search = $request->query('search', '');

        // Consultar desde el catálogo maestro de materiales
        $materiales = \App\Models\Material::when($search, fn($q) => $q->whereRaw('LOWER(nombre) LIKE ?', ['%' . mb_strtolower($search) . '%']))
            ->orderBy('nombre')
            ->limit(8)
            ->get()
            ->map(fn($m) => [
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
            'foto_url'                => 'sometimes|nullable|string|max:500',
            'items'                   => 'required|array',
            'items.*.id'              => 'required|integer',
            'items.*.cantidad'        => 'required|numeric|min:0',
            'items.*.precio_unitario' => 'required|numeric|min:0',
            'items.*.subtotal'        => 'required|numeric|min:0',
        ]);

        $camposActualizar = [];
        if (!empty($data['nombre'])) $camposActualizar['nombre'] = strtoupper(trim($data['nombre']));
        if (array_key_exists('foto_url', $data)) $camposActualizar['foto_url'] = $data['foto_url'];
        if (!empty($camposActualizar)) $fichaTecnica->update($camposActualizar);

        foreach ($data['items'] as $itemData) {
            FichaTecnicaItem::where('id', $itemData['id'])
                ->where('ficha_tecnica_id', $fichaTecnica->id)
                ->update([
                    'cantidad'        => $itemData['cantidad'],
                    'precio_unitario' => $itemData['precio_unitario'],
                    'subtotal'        => $itemData['subtotal'],
                ]);
        }

        $todos           = FichaTecnicaItem::where('ficha_tecnica_id', $fichaTecnica->id)->get();
        $costoMateriales = $todos->where('es_mano_obra', false)->sum('subtotal');
        $costoManoObra   = $todos->where('es_mano_obra', true)->sum('subtotal');

        $fichaTecnica->update([
            'costo_materiales' => $costoMateriales,
            'costo_mano_obra'  => $costoManoObra,
            'costo_total'      => $costoMateriales + $costoManoObra,
        ]);

        return response()->json(['mensaje' => 'Guardado correctamente']);
    }

    public function reimportar()
    {
        Artisan::call('fichas:importar');
        $output = Artisan::output();
        return response()->json(['mensaje' => trim($output)]);
    }
}
