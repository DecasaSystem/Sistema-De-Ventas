<?php

namespace App\Http\Controllers;

use App\Models\FichaTecnica;
use App\Models\FichaTecnicaItem;
use App\Models\Material;
use App\Services\Costos\FichaRetriever;
use App\Services\Costos\RecetasDeProductos;
use App\Support\PreciosVigentes;
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
                // Vinculada, el nombre del Excel quedó en la descripción: que se siga encontrando
                if (RecetasDeProductos::hayDescripcion()) $q->orWhereRaw('LOWER(ft.descripcion) LIKE ?', [$term]);
            });
        }

        if ($categoria = $request->query('categoria')) {
            $query->where('ft.categoria', $categoria);
        }

        $cols = [
            'ft.id', 'ft.nombre', 'ft.categoria', 'ft.costo_materiales', 'ft.costo_mano_obra',
            'ft.costo_total', 'ft.foto_url', 'ft.producto_id', 'p.nombre as producto_nombre',
            'p.foto_url as producto_foto',
        ];

        // La receta de una versión (la cama de 1.40) se compara contra el precio de esa
        // versión, no contra el del producto: `precio_adicional` es su precio completo
        // (0 = el del producto).
        if (RecetasDeProductos::hayVersiones()) {
            $query->leftJoin('producto_variante_configs as vc', 'vc.id', '=', 'ft.variante_config_id')
                  ->leftJoin('tipo_variante_opciones as vo', 'vo.id', '=', 'vc.opcion_id');
            $cols[] = 'ft.variante_config_id';
            $cols[] = 'vo.nombre as version_nombre';
            // La receta general de un producto con medidas no es el costo de ninguna: sin
            // precio con qué compararla (cada medida se vende a su precio).
            $cols[] = DB::raw('CASE
                WHEN ft.variante_config_id IS NULL AND EXISTS (
                    SELECT 1 FROM producto_variante_configs c2
                    JOIN tipos_variante t2 ON t2.id = c2.tipo_variante_id
                    WHERE c2.producto_id = ft.producto_id AND t2.afecta_precio = 1) THEN NULL
                WHEN vc.precio_adicional > 0 THEN vc.precio_adicional
                ELSE p.precio_base END AS producto_precio');
        } else {
            $cols[] = 'p.precio_base as producto_precio';
        }

        $fichas = $query
            ->orderBy('ft.categoria')
            ->orderBy('ft.nombre')
            ->get($cols);

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

    /**
     * GET /fichas-tecnicas/productos — los productos de Inventario con su receta (o "sin
     * receta", en espera) y las fichas sueltas con sugerencia. Ver RecetasDeProductos.
     */
    public function productos(RecetasDeProductos $recetas)
    {
        return response()->json($recetas->listado());
    }

    public function show(FichaTecnica $fichaTecnica)
    {
        return response()->json($this->conMedidas($fichaTecnica->load($this->relaciones())));
    }

    /**
     * `producto_con_medidas`: el producto tiene medidas (opciones que cambian el precio). Su
     * receta general no es el costo de ninguna medida, y la pantalla no le saca margen.
     */
    private function conMedidas(FichaTecnica $ficha): FichaTecnica
    {
        $con = $ficha->producto_id && RecetasDeProductos::hayVersiones() && DB::table('producto_variante_configs as c')
            ->join('tipos_variante as t', 't.id', '=', 'c.tipo_variante_id')
            ->where('c.producto_id', $ficha->producto_id)->where('t.afecta_precio', true)->exists();

        return $ficha->setAttribute('producto_con_medidas', $con);
    }

    /**
     * PATCH /fichas-tecnicas/{id}/producto — vincula (o desvincula, con null) el producto
     * del catálogo y, si se manda, la versión. Una sola receta por producto y versión.
     */
    public function vincularProducto(Request $request, FichaTecnica $fichaTecnica, RecetasDeProductos $recetas)
    {
        $data = $request->validate([
            'producto_id'        => 'present|nullable|integer|exists:productos,id',
            'variante_config_id' => 'nullable|integer',
        ]);

        $configId = $data['producto_id'] ? ($data['variante_config_id'] ?? null) : null;

        DB::transaction(function () use ($recetas, $fichaTecnica, $data, $configId) {
            $recetas->validarLugar($data['producto_id'], $configId, $fichaTecnica->id);

            // Toma el nombre y la foto del producto; el nombre que tenía queda de descripción
            $fichaTecnica->update(
                $recetas->camposAlVincular($fichaTecnica, $data['producto_id'], $configId)
                + ['producto_id' => $data['producto_id']]
                + $this->version($configId)
            );
        });

        // El nombre y la descripción son lo que indexa el cotizador
        if ($fichaTecnica->wasChanged('nombre') || $fichaTecnica->wasChanged('descripcion')) {
            $this->indexarDespues($fichaTecnica->id);
        }

        return response()->json($this->conMedidas($fichaTecnica->fresh()->load($this->relaciones(false))));
    }

    /** Producto y versión de la ficha, para mostrar (con la foto del producto si la ficha no tiene). */
    private function relaciones(bool $items = true): array
    {
        $r = $items ? ['items', self::PRODUCTO] : [self::PRODUCTO];
        if (RecetasDeProductos::hayVersiones()) $r[] = 'varianteConfig.opcion:id,nombre';

        return $r;
    }

    /** La columna de la versión solo si existe (los esquemas de las pruebas no siempre). */
    private function version(?int $configId): array
    {
        return RecetasDeProductos::hayVersiones() ? ['variante_config_id' => $configId] : [];
    }

    public function store(Request $request, RecetasDeProductos $recetas)
    {
        $data = $request->validate([
            'nombre'                  => 'required|string|max:255',
            'categoria'               => 'required|string|max:255',
            'foto_url'                => 'nullable|string|max:500',
            // La receta de un producto de Inventario (y, si se manda, de una de sus versiones)
            'producto_id'             => 'nullable|integer|exists:productos,id',
            'variante_config_id'      => 'nullable|integer',
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

        $productoId = $data['producto_id'] ?? null;
        $configId   = $productoId ? ($data['variante_config_id'] ?? null) : null;

        $ficha = DB::transaction(function () use ($data, $recetas, $productoId, $configId) {
            // Dentro de la transacción: dos personas creando la receta del mismo producto
            $recetas->validarLugar($productoId, $configId);

            $ficha = FichaTecnica::create([
                // La receta de un producto se llama como el producto (y su versión)
                'nombre'      => ($productoId ? $recetas->nombrePara($productoId, $configId) : null)
                                 ?? mb_strtoupper(trim($data['nombre'])),
                'categoria'   => mb_strtoupper(trim($data['categoria'])),
                // Sin foto propia se muestra la del producto: no se copia, para que si
                // cambian la foto en Inventario la receta la siga.
                'foto_url'    => $data['foto_url'] ?? null,
                'producto_id' => $productoId,
                'ruta_excel'  => null,
            ] + $this->version($configId));

            $porNombre = $this->materialesPorNombre($data['items']);
            $vigentes  = new PreciosVigentes();

            foreach ($data['items'] as $orden => $item) {
                $esManoObra = (bool) ($item['es_mano_obra'] ?? false);
                $materialId = $esManoObra ? null : $this->materialDe($item, $porNombre);
                $tarifaId   = $esManoObra ? ($item['tarifa_proceso_id'] ?? null) : null;
                $precio     = $vigentes->de($materialId, $tarifaId) ?? $item['precio_unitario'];

                FichaTecnicaItem::create([
                    'ficha_tecnica_id'  => $ficha->id,
                    'seccion'           => $item['seccion'] ?? null,
                    'descripcion'       => trim($item['descripcion']),
                    'cantidad'          => $item['cantidad'],
                    'unidad'            => $item['unidad'] ?? null,
                    'precio_unitario'   => $precio,
                    // El subtotal lo calcula el servidor, no se confía en el que manda el cliente
                    'subtotal'          => round($item['cantidad'] * $precio, 2),
                    'es_mano_obra'      => $esManoObra,
                    'material_id'       => $materialId,
                    'tarifa_proceso_id' => $tarifaId,
                    'orden'             => $orden,
                ]);
            }

            FichaTecnica::recalcularTotales($ficha->id);

            return $ficha;
        });

        $this->indexarDespues($ficha->id);

        return response()->json($this->conMedidas($ficha->fresh()->load($this->relaciones())), 201);
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
            'descripcion'             => 'sometimes|nullable|string|max:2000',
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

        // Lo que el cotizador indexa: nombre, descripción, categoría y secciones (ver FichaRetriever::textoDeFicha)
        $huella = fn () => [
            $fichaTecnica->fresh()->only(['nombre', 'descripcion', 'categoria']),
            FichaTecnicaItem::where('ficha_tecnica_id', $fichaTecnica->id)->distinct()->orderBy('seccion')->pluck('seccion')->all(),
        ];
        $huellaAntes = $huella();

        DB::transaction(function () use ($data, $fichaTecnica) {
            $camposActualizar = [];
            // Vinculada a un producto, el nombre es el del producto (lo cambia Inventario):
            // un nombre distinto que llegue de la pantalla no se guarda.
            if (!empty($data['nombre']) && ! $fichaTecnica->producto_id) {
                $camposActualizar['nombre'] = mb_strtoupper(trim($data['nombre']));
            }
            if (array_key_exists('descripcion', $data) && RecetasDeProductos::hayDescripcion()) {
                $camposActualizar['descripcion'] = trim((string) $data['descripcion']) ?: null;
            }
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
            $vigentes       = new PreciosVigentes();

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
                    $materialId = $esManoObra ? null : $this->materialDe($itemData, $porNombre);
                    $tarifaId   = $esManoObra ? ($itemData['tarifa_proceso_id'] ?? null) : null;
                    FichaTecnicaItem::create($this->conPrecioVigente($campos, $vigentes, $materialId, $tarifaId) + [
                        'ficha_tecnica_id'  => $fichaTecnica->id,
                        'es_mano_obra'      => $esManoObra,
                        'material_id'       => $materialId,
                        'tarifa_proceso_id' => $tarifaId,
                        'orden'             => $siguienteOrden++,
                    ]);
                    continue;
                }

                // Si cambió qué material es, se vuelve a enlazar con el catálogo
                if (! $actual->es_mano_obra && (array_key_exists('material_id', $itemData) || isset($campos['descripcion']))) {
                    $campos['material_id'] = $this->materialDe($itemData + ['descripcion' => $actual->descripcion], $porNombre);
                }

                $materialId = array_key_exists('material_id', $campos) ? $campos['material_id'] : $actual->material_id;
                $actual->update($this->conPrecioVigente($campos, $vigentes, $materialId, $actual->tarifa_proceso_id));
            }

            if (! FichaTecnicaItem::where('ficha_tecnica_id', $fichaTecnica->id)->exists()) {
                throw ValidationException::withMessages(['items' => 'La ficha debe quedar con al menos un ítem.']);
            }

            FichaTecnica::recalcularTotales($fichaTecnica->id);
        });

        if ($huella() !== $huellaAntes) {
            $this->indexarDespues($fichaTecnica->id);
        }

        return response()->json($this->conMedidas($fichaTecnica->fresh()->load($this->relaciones())));
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
     * El producto no se copia (no puede haber dos recetas del mismo lugar), salvo que se
     * diga de cuál es la copia: "receta de la 1.60 a partir de la del producto".
     * Los precios enlazados salen con el valor de hoy, como al guardar.
     */
    public function duplicar(Request $request, FichaTecnica $fichaTecnica, RecetasDeProductos $recetas)
    {
        $data = $request->validate([
            'nombre'             => 'nullable|string|max:255',
            'producto_id'        => 'nullable|integer|exists:productos,id',
            'variante_config_id' => 'nullable|integer',
        ]);

        $productoId = $data['producto_id'] ?? null;
        $configId   = $productoId ? ($data['variante_config_id'] ?? null) : null;

        $copia = DB::transaction(function () use ($data, $fichaTecnica, $recetas, $productoId, $configId) {
            $recetas->validarLugar($productoId, $configId);

            $copia = FichaTecnica::create([
                'nombre'      => ($productoId ? $recetas->nombrePara($productoId, $configId) : null)
                                 ?? mb_strtoupper(trim($data['nombre'] ?? '') ?: 'COPIA DE ' . $fichaTecnica->nombre),
                'categoria'   => $fichaTecnica->categoria,
                // Copia de un producto: se ve la foto del producto, no se copia
                'foto_url'    => $productoId ? null : $fichaTecnica->foto_url,
                'producto_id' => $productoId,
            ] + ($productoId ? $this->version($configId) : [])
              + (RecetasDeProductos::hayDescripcion() ? ['descripcion' => $fichaTecnica->descripcion] : []));

            $vigentes = new PreciosVigentes();
            foreach ($fichaTecnica->items as $item) {
                $campos = $item->only([
                    'seccion', 'descripcion', 'cantidad', 'unidad', 'precio_unitario',
                    'subtotal', 'es_mano_obra', 'material_id', 'tarifa_proceso_id', 'orden',
                ]);
                FichaTecnicaItem::create(
                    $this->conPrecioVigente($campos, $vigentes, $item->material_id, $item->tarifa_proceso_id)
                    + ['ficha_tecnica_id' => $copia->id]
                );
            }

            FichaTecnica::recalcularTotales($copia->id);

            return $copia;
        });

        $this->indexarDespues($copia->id);

        return response()->json($this->conMedidas($copia->fresh()->load($this->relaciones())), 201);
    }

    /**
     * El precio de un ítem enlazado (material del catálogo o trabajo de Tarifas) es el de
     * hoy, no el que manda la pantalla. La pantalla manda lo que tenía al abrir la ficha:
     * si mientras tanto cambió el precio del material o el incentivo del oficio, guardar
     * (aunque fuera solo la foto) devolvía la ficha a los precios viejos sin que nadie
     * lo notara. Y un precio escrito a mano en un ítem enlazado lo pisaba el próximo cambio
     * del catálogo. Lo que no está enlazado sigue con el precio que se escribió.
     */
    private function conPrecioVigente(array $campos, PreciosVigentes $vigentes, ?int $materialId, ?int $tarifaId): array
    {
        $precio = $vigentes->de($materialId, $tarifaId);
        if ($precio === null) return $campos;

        return array_merge($campos, [
            'precio_unitario' => $precio,
            'subtotal'        => round((float) $campos['cantidad'] * $precio, 2),
        ]);
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
