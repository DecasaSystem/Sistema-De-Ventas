<?php

namespace App\Http\Controllers;

use App\Models\FichaTecnica;
use App\Models\Material;
use App\Support\ColoresMaterial;
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
            'color'           => ['sometimes', 'nullable', \Illuminate\Validation\Rule::in(ColoresMaterial::COLORES)],
        ]);
        $data = $this->sinColorSiNoHay($data);

        if ($repetido = $this->otroConElNombre($data['nombre'])) {
            return $this->yaExiste($repetido);
        }

        $material = DB::transaction(function () use ($data) {
            $material = Material::create($data + $this->unidadNorm($data['unidad'] ?? null));
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
            'color'           => ['sometimes', 'nullable', \Illuminate\Validation\Rule::in(ColoresMaterial::COLORES)],
        ]);
        $data = $this->sinColorSiNoHay($data);

        // Solo al renombrar: si ya había dos con el mismo nombre (de antes), se pueden
        // seguir editando; lo que no se deja es crear un repetido nuevo.
        $renombra = isset($data['nombre'])
            && mb_strtolower(trim($data['nombre'])) !== mb_strtolower(trim($material->nombre));
        if ($renombra && ($repetido = $this->otroConElNombre($data['nombre'], $material->id))) {
            return $this->yaExiste($repetido);
        }

        $precioAnterior = (float) $material->precio_unitario;
        $precioNuevo    = (float) $data['precio_unitario'];
        $nombreAnterior = $material->nombre;
        $unidadAnterior = $material->unidad;

        // La unidad que ve el cotizador sigue a la escrita (antes se quedaba con la vieja)
        if (array_key_exists('unidad', $data) && $data['unidad'] !== $unidadAnterior) {
            $data += $this->unidadNorm($data['unidad']);
        }

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
     * GET /materiales/colores — los colores para marcar materiales y qué significa cada uno.
     */
    public function colores()
    {
        return response()->json(['colores' => ColoresMaterial::leyenda()]);
    }

    /**
     * PUT /materiales/colores — cambia lo que significa cada color (no toca los materiales).
     * Body: { colores: { rojo: "Falta actualizar el precio", ... } }
     */
    public function guardarColores(Request $request)
    {
        $data = $request->validate([
            'colores'   => 'required|array',
            'colores.*' => 'nullable|string|max:80',
        ]);

        return response()->json(['colores' => ColoresMaterial::guardar($data['colores'])]);
    }

    /** Sin la columna (esquemas de las pruebas, o antes de migrar) el color no se guarda. */
    private function sinColorSiNoHay(array $data): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('materiales', 'color')) unset($data['color']);

        return $data;
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
                // Para mostrar cuánto queda costando la ficha al reemplazar el material
                'ft.costo_total as ficha_costo',
                'ft.producto_id',
                'fti.id  as item_id',
                'fti.seccion',
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
     * POST /materiales/{id}/reemplazar — pasa este material a otro, pero solo en las fichas
     * que se escojan. Pedido del usuario (2026-11-10): "creo un material nuevo para
     * reemplazar otro en algunos productos, para no tener que ir producto por producto";
     * el material viejo se queda en el catálogo (lo siguen usando las demás fichas).
     * Body: { reemplazar_con_id: int, fichas: int[] }
     *
     * Igual que al eliminar con reemplazo: el ítem toma nombre, unidad y precio del nuevo y
     * conserva su cantidad (no se convierte entre unidades; la pantalla lo advierte).
     */
    public function reemplazar(Request $request, Material $material)
    {
        $data = $request->validate([
            'reemplazar_con_id' => 'required|integer|exists:materiales,id|not_in:' . $material->id,
            'fichas'            => 'required|array|min:1',
            'fichas.*'          => 'integer',
        ], [
            'reemplazar_con_id.not_in' => 'Escoge un material distinto al que se reemplaza.',
        ]);

        $nuevo = Material::findOrFail($data['reemplazar_con_id']);

        $resultado = DB::transaction(function () use ($material, $nuevo, $data) {
            $items = $this->itemsDe($material->id, $material->nombre)
                ->whereIn('ficha_tecnica_id', array_unique($data['fichas']));

            $fichaIds = (clone $items)->distinct()->pluck('ficha_tecnica_id');
            if ($fichaIds->isEmpty()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'fichas' => 'Ninguna de las fichas escogidas usa este material.',
                ]);
            }

            $precio = (float) $nuevo->precio_unitario;
            $cantidadItems = $items->update([
                'material_id'     => $nuevo->id,
                'descripcion'     => trim($nuevo->nombre),
                'unidad'          => $nuevo->unidad,
                'precio_unitario' => $precio,
                'subtotal'        => DB::raw('ROUND(cantidad * ' . $precio . ', 2)'),
                'updated_at'      => now(),
            ]);

            foreach ($fichaIds as $fichaId) {
                FichaTecnica::recalcularTotales($fichaId);
            }

            return ['items' => $cantidadItems, 'fichas' => $fichaIds->count()];
        });

        return response()->json($resultado + ['material' => $nuevo->only(['id', 'nombre'])]);
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
                ] + $this->unidadNorm($item->unidad));
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

    /**
     * Otro material que ya se llama así (sin mayúsculas ni espacios de los lados). Dos con
     * el mismo nombre salen repetidos en el buscador y los ítems escritos a mano se enlazan
     * siempre con el más viejo (`Material::idsPorNombre`): el otro queda sin usar.
     */
    private function otroConElNombre(string $nombre, ?int $salvo = null): ?Material
    {
        return Material::whereRaw('LOWER(TRIM(nombre)) = ?', [mb_strtolower(trim($nombre))])
            ->when($salvo, fn ($q) => $q->where('id', '!=', $salvo))
            ->orderBy('id')
            ->first();
    }

    private function yaExiste(Material $repetido)
    {
        return response()->json([
            'message'     => "Ya existe un material llamado «{$repetido->nombre}». Edita ese o usa otro nombre.",
            'existente_id' => $repetido->id,
        ], 422);
    }

    /** `unidad_norm` para guardar, si la tabla la tiene (los esquemas de las pruebas no siempre). */
    private function unidadNorm(?string $unidad): array
    {
        return \Illuminate\Support\Facades\Schema::hasColumn('materiales', 'unidad_norm')
            ? ['unidad_norm' => \App\Support\UnidadMaterial::normalizar($unidad)]
            : [];
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
