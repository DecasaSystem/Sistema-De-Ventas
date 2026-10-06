<?php

namespace App\Http\Controllers;

use App\Models\FichaTecnica;
use App\Models\Material;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MaterialController extends Controller
{
    public function index(Request $request)
    {
        // `usos`: en cuántas fichas aparece, para saber qué toca un cambio de precio
        $query = Material::query()->select('materiales.*')->selectSub(
            DB::table('ficha_tecnica_items')
                ->whereColumn('material_id', 'materiales.id')
                ->selectRaw('COUNT(DISTINCT ficha_tecnica_id)'),
            'usos',
        );

        if ($search = $request->query('search')) {
            $term = '%' . mb_strtolower($search) . '%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(nombre) LIKE ?',      [$term])
                  ->orWhereRaw('LOWER(descripcion) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(unidad) LIKE ?',      [$term]);
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

        $material = DB::transaction(function () use ($data) {
            $material = Material::create($data);
            // Los ítems que ya estaban escritos con este nombre pasan a ser de este material
            $this->enlazarPorNombre();
            return $material;
        });

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
        $unidadAnterior = $material->unidad;

        $usuarioId = $request->user()?->id;

        $afectados = DB::transaction(function () use ($material, $data, $precioNuevo, $precioAnterior, $nombreAnterior, $unidadAnterior, $usuarioId) {
            // Se buscan antes del cambio: los que no tienen material_id todavía se
            // reconocen por el nombre que tenían ANTES (si no, un renombre los soltaría).
            $items = $this->itemsDe($material->id, $nombreAnterior);

            $material->update($data);

            $renombrado = trim($material->nombre) !== trim($nombreAnterior);
            if ($precioNuevo === $precioAnterior && ! $renombrado && $material->unidad === $unidadAnterior) {
                return 0;
            }

            $fichaIds      = (clone $items)->distinct()->pluck('ficha_tecnica_id');
            $subtotalAntes = (float) (clone $items)->sum('subtotal');
            $cantidadTotal = (float) (clone $items)->sum('cantidad');

            if ($precioNuevo !== $precioAnterior) {
                DB::table('material_precio_historial')->insert([
                    'material_id'         => $material->id,
                    'material_nombre'     => $material->nombre,
                    'precio_anterior'     => $precioAnterior,
                    'precio_nuevo'        => $precioNuevo,
                    'productos_afectados' => $fichaIds->count(),
                    'impacto_total'       => round($cantidadTotal * $precioNuevo - $subtotalAntes, 2),
                    'usuario_id'          => $usuarioId,
                    'created_at'          => now(),
                ]);
            }

            $campos = [
                'material_id'     => $material->id,
                'descripcion'     => trim($material->nombre),
                'precio_unitario' => $precioNuevo,
                'subtotal'        => DB::raw('ROUND(cantidad * ' . $precioNuevo . ', 2)'),
                'updated_at'      => now(),
            ];
            // La unidad de cada ítem viene del Excel y puede variar; solo se pisa si la cambiaron aquí
            if ($material->unidad !== $unidadAnterior) $campos['unidad'] = $material->unidad;

            $items->update($campos);

            foreach ($fichaIds as $fichaId) {
                FichaTecnica::recalcularTotales($fichaId);
            }

            return $fichaIds->count();
        });

        $material->productos_afectados = $afectados;

        return response()->json($material);
    }

    /**
     * GET /materiales/{id}/historial — cambios de precio, del más reciente al más viejo.
     */
    public function historial(Material $material)
    {
        $cambios = DB::table('material_precio_historial as h')
            ->leftJoin('usuarios as u', 'u.id', '=', 'h.usuario_id')
            ->where('h.material_id', $material->id)
            ->orderByDesc('h.created_at')
            ->orderByDesc('h.id')
            ->limit(50)
            ->get([
                'h.id', 'h.precio_anterior', 'h.precio_nuevo', 'h.productos_afectados',
                'h.impacto_total', 'h.created_at', 'u.nombre as usuario',
            ]);

        return response()->json($cambios);
    }

    /**
     * Lista todas las fichas técnicas que usan este material.
     */
    public function usos(Material $material)
    {
        $usos = $this->itemsDe($material->id, $material->nombre, 'fti')
            ->join('fichas_tecnicas as ft', 'ft.id', '=', 'fti.ficha_tecnica_id')
            ->select(
                'ft.id   as ficha_id',
                'ft.nombre as ficha_nombre',
                'ft.categoria as ficha_categoria',
                'fti.id  as item_id',
                'fti.cantidad',
                'fti.unidad',
                'fti.precio_unitario',
                'fti.subtotal',
            )
            ->orderBy('ft.nombre')
            ->get();

        return response()->json([
            'material' => $material,
            'usos'     => $usos,
            'total'    => $usos->count(),
        ]);
    }

    /**
     * Elimina un material reemplazando o vaciando sus usos en fichas técnicas.
     * Body: { reemplazar_con_id?: int|null }
     *   - Si se provee id: sustituye material/descripcion/unidad/precio en todos los ítems
     *   - Si es null: deja los ítems con descripcion='' y precio=0
     */
    public function destroy(Request $request, Material $material)
    {
        $data = $request->validate([
            'reemplazar_con_id' => 'nullable|integer|exists:materiales,id',
        ]);

        DB::transaction(function () use ($material, $data) {
            $items = $this->itemsDe($material->id, $material->nombre);

            // Obtener fichas afectadas ANTES de modificar los ítems
            $fichaIds = (clone $items)->distinct()->pluck('ficha_tecnica_id');

            if (!empty($data['reemplazar_con_id'])) {
                $nuevo = Material::findOrFail($data['reemplazar_con_id']);
                $nuevoPrecio = (float) $nuevo->precio_unitario;
                $items->update([
                    'material_id'     => $nuevo->id,
                    'descripcion'     => $nuevo->nombre,
                    'unidad'          => $nuevo->unidad,
                    'precio_unitario' => $nuevoPrecio,
                    'subtotal'        => DB::raw("ROUND(cantidad * {$nuevoPrecio}, 2)"),
                    'updated_at'      => now(),
                ]);
            } else {
                // Limpiar sin reemplazar
                $items->update([
                    'material_id'     => null,
                    'descripcion'     => '',
                    'precio_unitario' => 0,
                    'subtotal'        => 0,
                    'updated_at'      => now(),
                ]);
            }

            // Recalcular totales de cada ficha afectada
            foreach ($fichaIds as $fichaId) {
                FichaTecnica::recalcularTotales($fichaId);
            }

            $material->delete();
        });

        return response()->json(['ok' => true]);
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
            ->whereNull('material_id')
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

        $nuevos = DB::transaction(function () use ($items, $existentes) {
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

            $this->enlazarPorNombre();

            return $nuevos;
        });

        return response()->json([
            'mensaje'     => "Importados $nuevos materiales nuevos",
            'total'       => Material::count(),
        ]);
    }

    /**
     * Ítems de material de las fichas que son de este material: los enlazados por id y,
     * mientras queden ítems viejos sin enlazar, los que se llaman igual.
     */
    private function itemsDe(int $materialId, string $nombre, string $alias = 'ficha_tecnica_items')
    {
        $tabla = $alias === 'ficha_tecnica_items' ? $alias : "ficha_tecnica_items as {$alias}";

        return DB::table($tabla)
            ->where("{$alias}.es_mano_obra", false)
            ->where(function ($q) use ($alias, $materialId, $nombre) {
                $q->where("{$alias}.material_id", $materialId)
                  ->orWhere(function ($q) use ($alias, $nombre) {
                      $q->whereNull("{$alias}.material_id")
                        ->whereRaw("LOWER(TRIM({$alias}.descripcion)) = ?", [mb_strtolower(trim($nombre))]);
                  });
            });
    }

    /** Enlaza con el catálogo los ítems de material que se llaman como un material y no tienen material_id. */
    private function enlazarPorNombre(): void
    {
        $nombres = DB::table('ficha_tecnica_items')
            ->where('es_mano_obra', false)
            ->whereNull('material_id')
            ->where('descripcion', '!=', '')
            ->distinct()
            ->pluck('descripcion');

        foreach (Material::idsPorNombre($nombres) as $clave => $id) {
            DB::table('ficha_tecnica_items')
                ->where('es_mano_obra', false)
                ->whereNull('material_id')
                ->whereRaw('LOWER(TRIM(descripcion)) = ?', [$clave])
                ->update(['material_id' => $id]);
        }
    }
}
