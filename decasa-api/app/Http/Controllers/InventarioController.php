<?php

namespace App\Http\Controllers;

use App\Events\InventarioActualizado;
use App\Models\Inventario;
use App\Models\InventarioMovimiento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventarioController extends Controller
{
    /**
     * GET /api/inventario/desglose-variantes?tienda_id=1|todas
     *
     * De las unidades que hay de un producto, cuántas tienen ya asignado un
     * tapizado o una medida concreta. No es una tabla paralela al inventario:
     * es un desglose de PARTE del mismo total, así que la suma de las
     * variantes nunca debería pasarse del total del producto. Lo que sobra son
     * unidades que existen pero a las que nadie les ha dicho todavía de qué
     * tela o medida son.
     *
     * Los dos desgloses (tapizado y medida) son ejes distintos del mismo
     * stock: no se suman entre sí. Por eso cada fila trae su 'tipo' y quien
     * los presente debe cerrarlos por separado.
     *
     * SIEMPRE se agrupa por tienda, incluso con tienda_id=todas. Sumar las
     * tiendas antes de comparar tapaba los descuadres: un producto en cero en
     * una tienda con una unidad marcada ahí quedaba cubierto por las unidades
     * de las otras tiendas, y el problema desaparecía del reporte. El stock no
     * se puede mover solo de una tienda a otra, así que cada tienda cuadra o
     * no cuadra por su cuenta.
     */
    public function desgloseVariantes(Request $request)
    {
        $request->validate(['tienda_id' => 'required']);
        $tiendaId = $request->query('tienda_id');
        $todas    = $tiendaId === 'todas';

        if (! $todas) {
            $request->validate(['tienda_id' => 'exists:tiendas,id']);
            $tid = (int) $tiendaId;
        }

        // Por tapizado/color (producto_variantes)
        $porTapizado = DB::table('inventario_variantes as iv')
            ->join('producto_variantes as pv', 'pv.id', '=', 'iv.variante_id')
            ->join('productos as p', 'p.id', '=', 'pv.producto_id')
            ->join('tiendas as t', 't.id', '=', 'iv.tienda_id')
            ->where('p.activo', true)
            ->when(! $todas, fn ($q) => $q->where('iv.tienda_id', $tid))
            ->groupBy('pv.producto_id', 'p.nombre', 'p.categoria', 'iv.tienda_id', 't.nombre',
                      'pv.id', 'pv.marca', 'pv.marca_tela', 'pv.nombre_color', 'pv.medida')
            ->select(
                'pv.producto_id',
                'p.nombre as producto',
                'p.categoria',
                'iv.tienda_id',
                't.nombre as tienda',
                'pv.marca', 'pv.marca_tela', 'pv.nombre_color', 'pv.medida',
                DB::raw('SUM(iv.cantidad_disponible) as disponible'),
                DB::raw('SUM(iv.cantidad_reservada) as reservado'),
            )
            ->get()
            ->map(fn ($r) => [
                'producto_id' => (int) $r->producto_id,
                'producto'    => $r->producto,
                'categoria'   => $r->categoria,
                'tienda_id'   => (int) $r->tienda_id,
                'tienda'      => $r->tienda,
                'tipo'        => 'Tapizado',
                'variante'    => trim(implode(' · ', array_filter([
                    $r->marca, $r->marca_tela, $r->nombre_color, $r->medida,
                ]))) ?: 'Sin nombre',
                'disponible'  => (int) $r->disponible,
                'reservado'   => (int) $r->reservado,
            ]);

        // Por medida/talla u otro tipo configurable (producto_variante_configs)
        $porConfig = DB::table('inventario_variante_configs as ivc')
            ->join('producto_variante_configs as pvc', 'pvc.id', '=', 'ivc.config_id')
            ->join('tipos_variante as tv', 'tv.id', '=', 'pvc.tipo_variante_id')
            ->join('tipo_variante_opciones as tvo', 'tvo.id', '=', 'pvc.opcion_id')
            ->join('productos as p', 'p.id', '=', 'pvc.producto_id')
            ->join('tiendas as t', 't.id', '=', 'ivc.tienda_id')
            ->where('p.activo', true)
            ->when(! $todas, fn ($q) => $q->where('ivc.tienda_id', $tid))
            ->groupBy('pvc.producto_id', 'p.nombre', 'p.categoria', 'ivc.tienda_id', 't.nombre',
                      'tv.nombre', 'tvo.nombre')
            ->select(
                'pvc.producto_id',
                'p.nombre as producto',
                'p.categoria',
                'ivc.tienda_id',
                't.nombre as tienda',
                'tv.nombre as tipo',
                'tvo.nombre as opcion',
                DB::raw('SUM(ivc.cantidad_disponible) as disponible'),
                DB::raw('SUM(ivc.cantidad_reservada) as reservado'),
            )
            ->get()
            ->map(fn ($r) => [
                'producto_id' => (int) $r->producto_id,
                'producto'    => $r->producto,
                'categoria'   => $r->categoria,
                'tienda_id'   => (int) $r->tienda_id,
                'tienda'      => $r->tienda,
                'tipo'        => $r->tipo,
                'variante'    => $r->opcion,
                'disponible'  => (int) $r->disponible,
                'reservado'   => (int) $r->reservado,
            ]);

        $filas = $porTapizado->concat($porConfig)->values();

        // El total del producto EN CADA TIENDA, para poder decir cuánto queda
        // sin asignar ahí. La clave es "producto|tienda" justamente para no
        // poder compararlo nunca contra un total de varias tiendas juntas.
        $ids = $filas->pluck('producto_id')->unique()->values();

        $totales = $ids->isEmpty() ? [] : DB::table('inventario')
            ->whereIn('producto_id', $ids)
            ->when(! $todas, fn ($q) => $q->where('tienda_id', $tid))
            ->select('producto_id', 'tienda_id', 'cantidad_disponible', 'cantidad_reservada')
            ->get()
            ->mapWithKeys(fn ($r) => [
                $r->producto_id.'|'.$r->tienda_id => [
                    'disponible' => (int) $r->cantidad_disponible,
                    'reservado'  => (int) $r->cantidad_reservada,
                ],
            ])
            ->all();

        return response()->json([
            'filas'   => $filas,
            'totales' => (object) $totales,
        ]);
    }

    /**
     * GET /api/inventario/resumen-categoria?tienda_id=1|todas&categoria=sofas
     *
     * El total de una categoría, para ponerlo arriba de la lista: "127 Sofás"
     * y, en la vista de todas las tiendas, cuántos hay en cada una. La lista
     * de productos llega paginada (20 en 20), así que sumar lo cargado en
     * pantalla daría un total incompleto mientras no se haya bajado hasta el
     * final — esto se resuelve aparte, en una sola consulta contra toda la
     * categoría.
     *
     * Mismo criterio que stockPorTienda(): solo tiendas activas, y con
     * cantidad_disponible/cantidad_reservada/stock_libre nombrados igual que
     * el desglose por producto para que el front reutilice el mismo formato.
     */
    public function resumenCategoria(Request $request)
    {
        $request->validate([
            'tienda_id' => 'required',
            'categoria' => 'required|string',
        ]);

        $tiendaId  = $request->query('tienda_id');
        $categoria = $request->query('categoria');
        $todas     = $tiendaId === 'todas';

        if (! $todas) {
            $request->validate(['tienda_id' => 'exists:tiendas,id']);
            $tid = (int) $tiendaId;
        }

        $porTienda = DB::table('inventario as inv')
            ->join('productos as p', 'p.id', '=', 'inv.producto_id')
            ->join('tiendas as t', 't.id', '=', 'inv.tienda_id')
            ->where('p.activo', true)
            ->where('p.categoria', $categoria)
            ->where('t.activa', true)
            ->when(! $todas, fn ($q) => $q->where('inv.tienda_id', $tid))
            ->groupBy('inv.tienda_id', 't.nombre')
            ->select(
                'inv.tienda_id',
                't.nombre as tienda_nombre',
                DB::raw('SUM(inv.cantidad_disponible) as cantidad_disponible'),
                DB::raw('SUM(inv.cantidad_reservada) as cantidad_reservada'),
            )
            ->get()
            ->map(fn ($r) => [
                'tienda_id'           => (int) $r->tienda_id,
                'tienda_nombre'       => $r->tienda_nombre,
                'cantidad_disponible' => (int) $r->cantidad_disponible,
                'cantidad_reservada'  => (int) $r->cantidad_reservada,
                'stock_libre'         => (int) $r->cantidad_disponible - (int) $r->cantidad_reservada,
            ])
            ->sortByDesc('stock_libre')
            ->values();

        // Cuántos productos hay en la categoría, tenga o no tenga inventario
        // todavía: uno recién creado sin stock cuenta para el catálogo aunque
        // no sume nada al total de unidades.
        $productos = DB::table('productos')
            ->where('activo', true)
            ->where('categoria', $categoria)
            ->count();

        return response()->json([
            'categoria'           => $categoria,
            'productos'           => $productos,
            'cantidad_disponible' => (int) $porTienda->sum('cantidad_disponible'),
            'cantidad_reservada'  => (int) $porTienda->sum('cantidad_reservada'),
            'stock_libre'         => (int) $porTienda->sum('stock_libre'),
            'por_tienda'          => $porTienda,
        ]);
    }

    /**
     * GET /api/inventario?tienda_id=1&search=sofa
     * GET /api/inventario?tienda_id=todas&search=sofa
     *
     * Devuelve inventario de una tienda o de todas las tiendas (agrupado).
     */
    public function index(Request $request)
    {
        $request->validate([
            'tienda_id' => 'required',
        ]);

        $tiendaId = $request->query('tienda_id');
        $search   = $request->query('search');
        $categoria = $request->query('categoria');

        // Descargar el inventario completo son ~19 viajes de 20 filas. Se deja
        // pedir mas por pagina para que el Excel salga en dos. El tope evita
        // que alguien pida el catalogo entero de una.
        $pedido  = $request->query('per_page');
        $perPage = is_numeric($pedido) ? min(max((int) $pedido, 1), 200) : 20;

        if ($tiendaId === 'todas') {
            return $this->inventarioTodas($search, $categoria, $perPage);
        }

        $request->validate([
            'tienda_id' => 'exists:tiendas,id',
        ]);

        $tid = (int) $tiendaId;

        $query = DB::table('productos')
            ->leftJoin('inventario', function ($join) use ($tid) {
                $join->on('inventario.producto_id', '=', 'productos.id')
                     ->where('inventario.tienda_id', '=', $tid);
            })
            ->where('productos.activo', true)
            ->select(
                DB::raw('COALESCE(inventario.id, 0) as id'),
                'productos.id as producto_id',
                DB::raw("{$tid} as tienda_id"),
                DB::raw('COALESCE(inventario.cantidad_disponible, 0) as cantidad_disponible'),
                DB::raw('COALESCE(inventario.cantidad_reservada, 0) as cantidad_reservada'),
                DB::raw('COALESCE(inventario.stock_minimo, 0) as stock_minimo'),
                'productos.nombre as prod_nombre',
                'productos.categoria as prod_categoria',
                'productos.precio_base as prod_precio_base',
                'productos.foto_url as prod_foto_url',
                'productos.personalizable as prod_personalizable',
                'productos.es_tapizado as prod_es_tapizado',
                'productos.tiene_tallas as prod_tiene_tallas',
                'productos.descripcion as prod_descripcion',
                'productos.medidas as prod_medidas',
                'productos.material as prod_material',
            );

        if ($search) {
            $term = "%{$search}%";
            $query->where(function ($q) use ($term) {
                $q->where('productos.nombre', 'like', $term)
                  ->orWhere('productos.categoria', 'like', $term);
            });
        }

        // El desempate por id no es cosmetico: sin el, "ordenar por cantidad"
        // deja el orden indefinido entre filas empatadas (aqui hay categorias
        // con 33 productos en el mismo numero), y con LIMIT/OFFSET eso permite
        // que una fila salga en dos paginas y otra en ninguna. Quien recorre
        // todas las paginas para descargar el Excel se lo llevaria repetido.
        if ($categoria) {
            $query->where('productos.categoria', $categoria);
            $query->orderBy(DB::raw('COALESCE(inventario.cantidad_disponible, 0)'), 'desc');
        } else {
            $query->orderBy('productos.nombre');
        }
        $query->orderBy('productos.id');

        return response()->json($query->paginate($perPage)->through(function ($inv) {
            $inv->stock_libre = $inv->cantidad_disponible - $inv->cantidad_reservada;
            $inv->bajo_stock  = $inv->cantidad_disponible <= $inv->stock_minimo;
            $inv->producto = (object) [
                'id'             => $inv->producto_id,
                'nombre'         => $inv->prod_nombre,
                'categoria'      => $inv->prod_categoria,
                'precio_base'    => (float) $inv->prod_precio_base,
                'foto_url'       => $inv->prod_foto_url,
                'personalizable' => (bool) $inv->prod_personalizable,
                'es_tapizado'    => (bool) $inv->prod_es_tapizado,
                'tiene_tallas'   => (bool) $inv->prod_tiene_tallas,
                'descripcion'    => $inv->prod_descripcion,
                'medidas'        => $inv->prod_medidas,
                'material'       => $inv->prod_material,
                'activo'         => true,
            ];
            return $inv;
        }));
    }

    /**
     * Devuelve inventario agrupado por producto de TODAS las tiendas.
     */
    private function inventarioTodas($search = null, $categoria = null, $perPage = 20)
    {
        $query = DB::table('productos')
            ->leftJoin('inventario', 'inventario.producto_id', '=', 'productos.id')
            ->where('productos.activo', true)
            ->select(
                'productos.id as producto_id',
                'productos.nombre',
                'productos.categoria',
                'productos.precio_base',
                'productos.foto_url',
                'productos.personalizable',
                'productos.es_tapizado',
                'productos.tiene_tallas',
                'productos.descripcion',
                'productos.medidas',
                'productos.material',
                DB::raw('COALESCE(SUM(inventario.cantidad_disponible), 0) as cantidad_disponible'),
                DB::raw('COALESCE(SUM(inventario.cantidad_reservada), 0) as cantidad_reservada'),
                DB::raw('COUNT(DISTINCT inventario.tienda_id) as tiendas_count'),
            )
            ->groupBy(
                'productos.id',
                'productos.nombre',
                'productos.categoria',
                'productos.precio_base',
                'productos.foto_url',
                'productos.personalizable',
                'productos.es_tapizado',
                'productos.tiene_tallas',
                'productos.descripcion',
                'productos.medidas',
                'productos.material',
            );

        if ($search) {
            $term = "%{$search}%";
            $query->where(function ($q) use ($term) {
                $q->where('productos.nombre', 'like', $term)
                  ->orWhere('productos.categoria', 'like', $term);
            });
        }

        if ($categoria) {
            $query->where('productos.categoria', $categoria);
            $query->orderByRaw('COALESCE(SUM(inventario.cantidad_disponible), 0) DESC');
        } else {
            $query->orderBy('productos.nombre');
        }
        $query->orderBy('productos.id');   // desempate, ver index()

        $pagina = $query->paginate($perPage);

        // Cuánto hay en CADA tienda, no solo el total. Antes solo se decía en
        // cuántas tiendas está, que no sirve para saber de dónde traerlo.
        //
        // Se resuelve en una sola consulta para los 20 productos de la página:
        // preguntarlo producto por producto serían 20 viajes a la base.
        $desglose = $this->stockPorTienda(
            collect($pagina->items())->pluck('producto_id')->all()
        );

        return response()->json($pagina
            ->through(function ($inv) use ($desglose) {
                $disp = (int) $inv->cantidad_disponible;
                $res  = (int) $inv->cantidad_reservada;
                return (object) [
                    'id'                  => $inv->producto_id,
                    'producto_id'         => $inv->producto_id,
                    'cantidad_disponible' => $disp,
                    'cantidad_reservada'  => $res,
                    'stock_libre'         => $disp - $res,
                    'stock_minimo'        => 0,
                    'bajo_stock'          => false,
                    'tiendas_count'       => (int) $inv->tiendas_count,
                    'por_tienda'          => $desglose[$inv->producto_id] ?? [],
                    'producto'            => (object) [
                        'id'             => $inv->producto_id,
                        'nombre'         => $inv->nombre,
                        'categoria'      => $inv->categoria,
                        'precio_base'    => (float) $inv->precio_base,
                        'foto_url'       => $inv->foto_url,
                        'personalizable' => (bool) $inv->personalizable,
                        'es_tapizado'    => (bool) $inv->es_tapizado,
                        'tiene_tallas'   => (bool) $inv->tiene_tallas,
                        'descripcion'    => $inv->descripcion,
                        'medidas'        => $inv->medidas,
                        'material'       => $inv->material,
                        'activo'         => true,
                    ],
                ];
            }));
    }

    /**
     * Stock de cada producto tienda por tienda: [producto_id => [ {tienda...} ]].
     *
     * Ordenado de mayor a menor stock libre, que es el orden en que le sirve a
     * quien busca de dónde sacar el producto.
     */
    private function stockPorTienda(array $productoIds): array
    {
        if (empty($productoIds)) return [];

        return DB::table('inventario as inv')
            ->join('tiendas as t', 't.id', '=', 'inv.tienda_id')
            ->whereIn('inv.producto_id', $productoIds)
            ->where('t.activa', true)
            // Solo donde hay algo. Casi todo producto tiene fila en las seis
            // tiendas, así que listar los ceros llenaría la tarjeta de ruido
            // justo cuando lo que se busca es de dónde traerlo.
            ->where(fn($q) => $q->where('inv.cantidad_disponible', '>', 0)
                                ->orWhere('inv.cantidad_reservada', '>', 0))
            ->select(
                'inv.producto_id',
                'inv.tienda_id',
                't.nombre as tienda_nombre',
                't.es_fabrica',
                'inv.cantidad_disponible',
                'inv.cantidad_reservada',
            )
            ->get()
            ->map(fn($r) => [
                'producto_id'         => (int) $r->producto_id,
                'tienda_id'           => (int) $r->tienda_id,
                'tienda_nombre'       => $r->tienda_nombre,
                'es_fabrica'          => (bool) $r->es_fabrica,
                'cantidad_disponible' => (int) $r->cantidad_disponible,
                'cantidad_reservada'  => (int) $r->cantidad_reservada,
                'stock_libre'         => (int) $r->cantidad_disponible - (int) $r->cantidad_reservada,
            ])
            ->groupBy('producto_id')
            ->map(fn($filas) => $filas
                ->sortByDesc('stock_libre')
                ->values()
                ->all())
            ->all();
    }

    /**
     * POST /api/inventario/entrada
     * Agrega stock a una tienda o a todas las tiendas donde existe el producto.
     */
    public function entrada(Request $request)
    {
        $data = $request->validate([
            'producto_id' => 'required|exists:productos,id',
            'tienda_id'   => 'required',
            'cantidad'    => 'required|integer|min:1',
            'motivo'      => 'nullable|string|max:200',
        ]);

        if ($data['tienda_id'] === 'todas') {
            if ($request->user()->rol !== 'supervisor') {
                abort(403, 'Solo los supervisores pueden agregar stock a todas las tiendas.');
            }
            return $this->entradaTodas($data, $request);
        }

        $request->validate([
            'tienda_id' => 'exists:tiendas,id',
        ]);

        $inv = DB::transaction(function () use ($data, $request) {
            $inv = Inventario::firstOrCreate(
                ['producto_id' => $data['producto_id'], 'tienda_id' => $data['tienda_id']],
                ['cantidad_disponible' => 0, 'cantidad_reservada' => 0, 'stock_minimo' => 0]
            );

            $inv->increment('cantidad_disponible', $data['cantidad']);

            InventarioMovimiento::create([
                'producto_id' => $data['producto_id'],
                'tienda_id'   => $data['tienda_id'],
                'tipo'        => 'entrada',
                'cantidad'    => $data['cantidad'],
                'motivo'      => $data['motivo'] ?? 'Entrada manual',
                'usuario_id'  => $request->user()->id,
            ]);

            return $inv;
        });

        event(new InventarioActualizado((int) $data['tienda_id'], (int) $data['producto_id'], 'entrada'));

        $inv->load('producto:id,nombre,categoria');
        $inv->stock_libre = $inv->cantidad_disponible - $inv->cantidad_reservada;
        $inv->bajo_stock  = $inv->cantidad_disponible <= $inv->stock_minimo;

        return response()->json($inv, 201);
    }

    /**
     * Agrega stock a TODAS las tiendas donde existe el producto.
     */
    private function entradaTodas($data, $request)
    {
        $inventario = Inventario::with('tienda:id,nombre')->where('producto_id', $data['producto_id'])->get();

        if ($inventario->isEmpty()) {
            abort(422, 'El producto no existe en ninguna tienda.');
        }

        $motivo = $data['motivo'] ?? 'Entrada manual (todas las tiendas)';
        $usuarioId = $request->user()->id;

        DB::transaction(function () use ($inventario, $data, $motivo, $usuarioId) {
            foreach ($inventario as $inv) {
                Inventario::where('id', $inv->id)
                    ->increment('cantidad_disponible', $data['cantidad']);

                $tiendaNombre = $inv->tienda ? $inv->tienda->nombre : "Tienda #{$inv->tienda_id}";

                InventarioMovimiento::create([
                    'producto_id' => $data['producto_id'],
                    'tienda_id'   => $inv->tienda_id,
                    'tipo'        => 'entrada',
                    'cantidad'    => $data['cantidad'],
                    'motivo'      => "{$motivo} — {$tiendaNombre}",
                    'usuario_id'  => $usuarioId,
                ]);
            }
        });

        $producto = \App\Models\Producto::find($data['producto_id']);

        return response()->json([
            'producto_id'       => $data['producto_id'],
            'producto'          => (object) [
                'id'          => $producto->id,
                'nombre'      => $producto->nombre,
                'categoria'   => $producto->categoria,
                'precio_base' => $producto->precio_base,
            ],
            'cantidad_disponible' => $inventario->sum('cantidad_disponible') + ($data['cantidad'] * $inventario->count()),
            'cantidad_reservada'  => $inventario->sum('cantidad_reservada'),
            'stock_libre'         => $inventario->sum(function ($i) {
                return $i->cantidad_disponible - $i->cantidad_reservada;
            }) + ($data['cantidad'] * $inventario->count()),
            'stock_minimo'        => 0,
            'bajo_stock'          => false,
            'tiendas_count'       => $inventario->count(),
            'tiendas_afectadas'   => $inventario->count(),
        ], 201);
    }

    /**
     * POST /api/inventario/salida
     * Quita stock disponible de una tienda (corrección manual).
     */
    public function salida(Request $request)
    {
        $data = $request->validate([
            'producto_id' => 'required|exists:productos,id',
            'tienda_id'   => 'required|exists:tiendas,id',
            'cantidad'    => 'required|integer|min:1',
            'motivo'      => 'nullable|string|max:200',
        ]);

        $user = $request->user();
        if ($user->rol === 'vendedor' && $user->tienda_default_id != $data['tienda_id']) {
            abort(403, 'Solo puedes quitar stock de tu propia tienda.');
        }

        $inv = DB::transaction(function () use ($data, $request) {
            $inv = Inventario::where('producto_id', $data['producto_id'])
                ->where('tienda_id', $data['tienda_id'])
                ->lockForUpdate()
                ->first();

            if (!$inv) {
                throw new \RuntimeException('Este producto no tiene inventario en la tienda seleccionada.');
            }

            $libre = $inv->cantidad_disponible - $inv->cantidad_reservada;
            if ($data['cantidad'] > $libre) {
                throw new \RuntimeException(
                    "No se puede quitar {$data['cantidad']} unidades. Stock libre: {$libre}."
                );
            }

            // Validar que el stock base resultante no quede por debajo de lo asignado a variantes
            $asignadoConfigs = DB::table('inventario_variante_configs as ivc')
                ->join('producto_variante_configs as pvc', 'ivc.config_id', '=', 'pvc.id')
                ->where('ivc.tienda_id', $data['tienda_id'])
                ->where('pvc.producto_id', $data['producto_id'])
                ->sum('ivc.cantidad_disponible');

            $asignadoVariantes = DB::table('inventario_variantes as iv')
                ->join('producto_variantes as pv', 'iv.variante_id', '=', 'pv.id')
                ->where('iv.tienda_id', $data['tienda_id'])
                ->where('pv.producto_id', $data['producto_id'])
                ->sum('iv.cantidad_disponible');

            $maxAsignado = max((int) $asignadoConfigs, (int) $asignadoVariantes);
            $nuevoDisponible = $inv->cantidad_disponible - $data['cantidad'];

            if ($nuevoDisponible < $maxAsignado) {
                $puedeQuitar = $inv->cantidad_disponible - $maxAsignado;
                if ($puedeQuitar <= 0) {
                    throw new \RuntimeException(
                        "Las {$maxAsignado} unidad(es) de este producto ya están asignadas a una tela/opción concreta. ".
                        "Para quitar esta unidad, primero quita el stock desde la pastilla de esa tela/opción y luego vuelve a intentarlo aquí."
                    );
                }
                throw new \RuntimeException(
                    "Hay {$maxAsignado} unidad(es) asignadas a una tela/opción concreta y no se pueden quitar desde aquí. ".
                    "Solo puedes quitar hasta {$puedeQuitar} (lo que no está asignado)."
                );
            }

            $inv->decrement('cantidad_disponible', $data['cantidad']);

            InventarioMovimiento::create([
                'producto_id' => $data['producto_id'],
                'tienda_id'   => $data['tienda_id'],
                'tipo'        => 'salida',
                'cantidad'    => $data['cantidad'],
                'motivo'      => $data['motivo'] ?? 'Ajuste manual',
                'usuario_id'  => $request->user()->id,
            ]);

            return $inv->fresh();
        });

        event(new InventarioActualizado((int) $data['tienda_id'], (int) $data['producto_id'], 'salida'));

        $inv->load('producto:id,nombre,categoria');
        $inv->stock_libre = $inv->cantidad_disponible - $inv->cantidad_reservada;
        $inv->bajo_stock  = $inv->cantidad_disponible <= $inv->stock_minimo;

        return response()->json($inv, 201);
    }

    public function movimientos(Request $request, int $productoId)
    {
        $tiendaId = $request->user()->tienda_default_id;

        if ($request->user()->rol === 'supervisor' && $request->query('tienda_id')) {
            $tiendaId = (int) $request->query('tienda_id');
        }

        $movimientos = InventarioMovimiento::with('usuario:id,nombre', 'variante:id,marca,marca_tela,nombre_color')
            ->where('producto_id', $productoId)
            ->where('tienda_id', $tiendaId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json($movimientos);
    }
}
