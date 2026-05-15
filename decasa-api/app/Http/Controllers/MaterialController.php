<?php

namespace App\Http\Controllers;

use App\Models\FichaTecnica;
use App\Models\FichaTecnicaItem;
use App\Models\Material;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MaterialController extends Controller
{
    public function index(Request $request)
    {
        $query = Material::query();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', "%$search%")
                  ->orWhere('descripcion', 'like', "%$search%")
                  ->orWhere('unidad', 'like', "%$search%");
            });
        }

        $materiales = $query->orderBy('nombre')->get();

        return response()->json($materiales);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'          => 'required|string|max:255',
            'descripcion'     => 'nullable|string|max:500',
            'unidad'          => 'nullable|string|max:100',
            'precio_unitario' => 'required|numeric|min:0',
        ]);

        $material = Material::create($data);

        return response()->json($material, 201);
    }

    public function update(Request $request, Material $material)
    {
        $data = $request->validate([
            'nombre'          => 'sometimes|required|string|max:255',
            'descripcion'     => 'nullable|string|max:500',
            'unidad'          => 'nullable|string|max:100',
            'precio_unitario' => 'required|numeric|min:0',
        ]);

        $precioAnterior = (float) $material->precio_unitario;
        $precioNuevo    = (float) $data['precio_unitario'];
        $nombreAnterior = $material->nombre;

        $material->update($data);

        // Si cambió el precio, propagar a todos los ítems con ese nombre
        if ($precioNuevo !== $precioAnterior || (isset($data['nombre']) && $data['nombre'] !== $nombreAnterior)) {
            $nombre = $material->nombre;

            // Actualizar precio y subtotal en un solo UPDATE eficiente
            DB::table('ficha_tecnica_items')
                ->whereRaw('TRIM(descripcion) = TRIM(?)', [$nombre])
                ->where('es_mano_obra', false)
                ->update([
                    'precio_unitario' => $precioNuevo,
                    'subtotal'        => DB::raw("ROUND(cantidad * $precioNuevo, 2)"),
                    'updated_at'      => now(),
                ]);

            // Recalcular totales de todas las fichas afectadas
            $fichaIds = FichaTecnicaItem::whereRaw('TRIM(descripcion) = TRIM(?)', [$nombre])
                ->pluck('ficha_tecnica_id')
                ->unique();

            foreach ($fichaIds as $fichaId) {
                $todos           = FichaTecnicaItem::where('ficha_tecnica_id', $fichaId)->get();
                $costoMateriales = $todos->where('es_mano_obra', false)->sum('subtotal');
                $costoManoObra   = $todos->where('es_mano_obra', true)->sum('subtotal');

                FichaTecnica::where('id', $fichaId)->update([
                    'costo_materiales' => $costoMateriales,
                    'costo_mano_obra'  => $costoManoObra,
                    'costo_total'      => $costoMateriales + $costoManoObra,
                ]);
            }

            $material->productos_afectados = $fichaIds->count();
        } else {
            $material->productos_afectados = 0;
        }

        return response()->json($material);
    }

    /**
     * Importa materiales únicos desde ficha_tecnica_items al catálogo.
     * Usa precio promedio de todos los items que usan ese material.
     */
    public function importar()
    {
        $existentes = Material::pluck('nombre')->map(fn($n) => trim(strtoupper($n)))->flip();

        $items = DB::table('ficha_tecnica_items')
            ->where('es_mano_obra', false)
            ->whereNotNull('descripcion')
            ->where('descripcion', '!=', '')
            ->select(
                DB::raw('TRIM(descripcion) as nombre'),
                'unidad',
                DB::raw('ROUND(AVG(precio_unitario), 0) as precio_promedio'),
                DB::raw('COUNT(*) as usos')
            )
            ->groupBy(DB::raw('TRIM(descripcion)'), 'unidad')
            ->orderByDesc('usos')
            ->get();

        $nuevos = 0;
        foreach ($items as $item) {
            $key = strtoupper(trim($item->nombre));
            if ($existentes->has($key)) continue;

            Material::create([
                'nombre'          => $item->nombre,
                'unidad'          => $item->unidad,
                'precio_unitario' => $item->precio_promedio,
            ]);
            $existentes->put($key, true);
            $nuevos++;
        }

        return response()->json([
            'mensaje'     => "Importados $nuevos materiales nuevos",
            'total'       => Material::count(),
        ]);
    }
}
