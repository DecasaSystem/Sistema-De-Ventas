<?php

namespace App\Http\Controllers;

use App\Models\Inventario;
use App\Models\InventarioMovimiento;
use App\Models\InventarioVariante;
use App\Models\Producto;
use App\Models\ProductoVariante;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductoController extends Controller
{
    /**
     * GET /api/productos?search=silla&tienda_id=1
     *
     * Devuelve productos activos. Si se pasa tienda_id, incluye
     * el stock de esa tienda en cada producto (sin N+1).
     */
    public function index(Request $request)
    {
        $query = Producto::where('activo', true);

        if ($search = $request->query('search')) {
            $term = '%' . mb_strtolower($search) . '%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(nombre) LIKE ?',    [$term])
                  ->orWhereRaw('LOWER(categoria) LIKE ?', [$term]);
            });
        }

        $limit    = (int) $request->query('limit', 0);
        $productos = $limit > 0
            ? $query->orderBy('categoria')->orderBy('nombre')->limit($limit)->get()
            : $query->orderBy('categoria')->orderBy('nombre')->get();

        if ($tiendaId = $request->query('tienda_id')) {
            $productoIds = $productos->pluck('id');

            $inventario = Inventario::where('tienda_id', $tiendaId)
                ->whereIn('producto_id', $productoIds)
                ->get()
                ->keyBy('producto_id');

            // Variantes con su stock en esta tienda
            $variantesIds = ProductoVariante::whereIn('producto_id', $productoIds)
                ->where('activo', true)
                ->pluck('id');

            $stockVariantes = InventarioVariante::where('tienda_id', $tiendaId)
                ->whereIn('variante_id', $variantesIds)
                ->get()
                ->keyBy('variante_id');

            $todasVariantes = ProductoVariante::whereIn('producto_id', $productoIds)
                ->where('activo', true)
                ->orderBy('marca_tela')->orderBy('nombre_color')
                ->get()
                ->groupBy('producto_id');

            $productos = $productos->map(function ($p) use ($inventario, $todasVariantes, $stockVariantes) {
                $inv = $inventario->get($p->id);
                $p->stock_disponible = $inv?->cantidad_disponible ?? 0;
                $p->stock_reservado  = $inv?->cantidad_reservada  ?? 0;
                $p->stock_minimo     = $inv?->stock_minimo        ?? 1;

                $variantes = $todasVariantes->get($p->id, collect())->map(function ($v) use ($stockVariantes) {
                    $s = $stockVariantes->get($v->id);
                    $v->stock_disponible = $s?->cantidad_disponible ?? 0;
                    $v->stock_reservado  = $s?->cantidad_reservada  ?? 0;
                    $v->stock_libre      = ($s?->cantidad_disponible ?? 0) - ($s?->cantidad_reservada ?? 0);
                    return $v;
                });

                $p->variantes = $variantes->values();
                return $p;
            });
        }

        // Lo que se vende en juego: las opciones cuyo juego trae otro número
        // de piezas (alas de a 4 en un producto de a 2). Una sola consulta,
        // y solo si en la lista hay alguno en juego.
        $enJuego = $productos->filter(fn ($p) => $p->seVendeEnJuego())->pluck('id');
        if ($enJuego->isNotEmpty()) {
            $propias = DB::table('producto_variante_configs')
                ->whereIn('producto_id', $enJuego)->whereNotNull('piezas_por_juego')
                ->get(['id', 'producto_id', 'piezas_por_juego'])
                ->groupBy('producto_id');
            $productos->each(function ($p) use ($propias) {
                if ($p->seVendeEnJuego()) {
                    $p->piezas_opciones = $propias->get($p->id, collect())->pluck('piezas_por_juego', 'id');
                }
            });
        }

        return response()->json($productos->values());
    }

    /**
     * GET /api/productos/{id}?tienda_id=1
     */
    public function show(Request $request, int $id)
    {
        $producto = Producto::where('activo', true)->findOrFail($id);

        if ($tiendaId = $request->query('tienda_id')) {
            $inv = Inventario::where('producto_id', $id)
                ->where('tienda_id', $tiendaId)
                ->first();

            $producto->stock_disponible = $inv?->cantidad_disponible ?? 0;
            $producto->stock_reservado  = $inv?->cantidad_reservada  ?? 0;
            $producto->stock_minimo     = $inv?->stock_minimo        ?? 1;
        }

        return response()->json($producto);
    }

    /**
     * GET /api/productos/sugerencias?q=term
     *
     * "¿Quizás quisiste decir…?" — productos parecidos por similitud cuando la
     * búsqueda exacta no encontró nada. Usa similar_text sobre el nombre + bonus
     * por palabras compartidas.
     */
    public function sugerencias(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) return response()->json([]);

        $qLower    = mb_strtolower($q);
        $palabras  = array_filter(preg_split('/\s+/', $qLower), fn ($w) => mb_strlen($w) >= 3);

        $productos = Producto::where('activo', true)->get(['id', 'nombre', 'categoria']);

        $scored = $productos->map(function ($p) use ($qLower, $palabras) {
            $nombre = mb_strtolower($p->nombre ?? '');
            $cat    = mb_strtolower($p->categoria ?? '');

            similar_text($qLower, $nombre, $pctNombre);
            similar_text($qLower, $cat,    $pctCat);

            $bonus = 0;
            foreach ($palabras as $w) {
                if (str_contains($nombre, $w)) $bonus += 25;
                elseif (str_contains($cat, $w)) $bonus += 10;
            }

            return ['p' => $p, 'score' => max($pctNombre, $pctCat * 0.5) + $bonus];
        })
        ->filter(fn ($x) => $x['score'] >= 34)
        ->sortByDesc('score')
        ->take(5)
        ->map(fn ($x) => ['id' => $x['p']->id, 'nombre' => $x['p']->nombre, 'categoria' => $x['p']->categoria])
        ->values();

        return response()->json($scored);
    }

    /**
     * POST /api/productos
     *
     * Crea un nuevo producto y sus registros de inventario (en 0) para las
     * tiendas indicadas. Supervisor elige una, varias o todas las tiendas;
     * vendedor siempre queda restringido a su tienda predeterminada.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $rules = [
            'nombre'         => 'required|string|max:150',
            'categoria'      => 'nullable|string|max:80',
            'precio_base'    => 'required|numeric|min:0',
            'personalizable' => 'nullable|boolean',
            'es_tapizado'    => 'nullable|boolean',
            'descripcion'    => 'nullable|string',
            'foto_url'       => 'nullable|string|max:255',
            'medidas'        => 'nullable|string|max:200',
            'material'       => 'nullable|string|max:200',
        ];

        if ($user->rol === 'supervisor') {
            $rules['tiendas']   = 'required|array|min:1';
            $rules['tiendas.*'] = 'integer|exists:tiendas,id';
        }

        $data = $request->validate($rules);

        $producto = Producto::create([
            'nombre'         => $data['nombre'],
            'categoria'      => $data['categoria'] ?? null,
            'precio_base'    => $data['precio_base'],
            'personalizable' => $data['personalizable'] ?? false,
            'es_tapizado'    => $data['es_tapizado'] ?? false,
            'descripcion'    => $data['descripcion'] ?? null,
            'foto_url'       => $data['foto_url'] ?? null,
            'medidas'        => $data['medidas'] ?? null,
            'material'       => $data['material'] ?? null,
            'activo'         => true,
        ]);

        $tiendaIds = $user->rol === 'vendedor'
            ? [$user->tienda_default_id]
            : $data['tiendas'];

        foreach ($tiendaIds as $tiendaId) {
            Inventario::firstOrCreate(
                ['producto_id' => $producto->id, 'tienda_id' => $tiendaId],
                ['cantidad_disponible' => 0, 'cantidad_reservada' => 0, 'stock_minimo' => 1]
            );
        }

        return response()->json($producto, 201);
    }

    /**
     * GET /api/productos/categorias
     * Devuelve las categorías distintas de productos activos (para autocompletado).
     */
    public function categorias()
    {
        $cats = Producto::where('activo', true)
            ->whereNotNull('categoria')
            ->where('categoria', '!=', '')
            ->distinct()
            ->orderBy('categoria')
            ->pluck('categoria');

        return response()->json($cats);
    }

    /**
     * PATCH /api/productos/{id}
     *
     * Permite cambiar precio_base y/o foto_url del producto.
     * Accesible por vendedores y supervisores.
     */
    public function update(Request $request, int $id)
    {
        $producto = Producto::findOrFail($id);

        $data = $request->validate([
            'precio_base'  => 'sometimes|numeric|min:0',
            'foto_url'     => 'sometimes|nullable|string|max:500',
            'foto_url_2'   => 'sometimes|nullable|string|max:500',
            'es_tapizado'  => 'sometimes|boolean',
            'tiene_tallas' => 'sometimes|boolean',
            // Se podía marcar al crear pero no después: si se olvidaba, no
            // había forma de vender ese producto como personalizado sin
            // volver a crearlo.
            'personalizable' => 'sometimes|boolean',
            'nombre'       => 'sometimes|string|max:150',
            'descripcion'  => 'sometimes|nullable|string',
            'medidas'      => 'sometimes|nullable|string|max:200',
            'material'     => 'sometimes|nullable|string|max:200',
            'categoria'    => 'sometimes|nullable|string|max:80',
        ]);

        if (empty($data)) {
            return response()->json(['message' => 'Nada que actualizar.'], 422);
        }

        $producto->update($data);

        return response()->json($producto);
    }

    /**
     * POST /api/productos/{id}/venta-por-juego
     *
     * Activa, cambia o quita la venta en juego (unas mesas que vienen de a 2),
     * y el número de piezas de cada opción si el juego cambia según la opción
     * (unas alas vintage de a 2 o de a 4).
     *
     * Con el juego activo el stock se cuenta por PIEZAS. Lo que ya estaba
     * cargado casi siempre está contado en juegos ("pongo 1"), así que al
     * activarlo se ofrece convertirlo (`convertir_stock`), y al quitarlo,
     * volver a juegos. Cambiar un número con el juego ya activo no convierte
     * por defecto —las piezas que hay son las mismas—, pero se puede pedir:
     * sirve cuando el stock de esa opción se convirtió con el número
     * equivocado.
     *
     * La regla es una sola para todo: cada fila de stock se multiplica por
     * (piezas nuevas / piezas de antes) de SU opción, contando 1 cuando no se
     * vendía en juego (la unidad guardada era el juego entero). El total de la
     * tienda y el reparto por tela no son de una opción: se rearman sumando lo
     * de cada opción más lo que no está en ninguna, con el número del producto.
     *
     * Convertir no se deja mientras haya algo apartado o en camino: esas
     * órdenes, traslados y surtidos guardaron su cantidad en la unidad vieja,
     * y al moverse después descontarían una cantidad que ya no corresponde.
     */
    public function ventaPorJuego(Request $request, int $id)
    {
        $producto = Producto::findOrFail($id);

        $data = $request->validate([
            'piezas_por_juego'   => 'nullable|integer|min:2|max:50',
            'precio_pieza'       => 'nullable|numeric|min:0',
            'convertir_stock'    => 'sometimes|boolean',
            // { config_id: piezas | null }. Las que no vienen quedan como están.
            'piezas_opciones'    => 'sometimes|array',
            'piezas_opciones.*'  => 'nullable|integer|min:2|max:50',
        ]);

        $configs = DB::table('producto_variante_configs')
            ->where('producto_id', $producto->id)
            ->get(['id', 'tipo_variante_id', 'piezas_por_juego'])
            ->keyBy('id');

        $antesD   = $producto->seVendeEnJuego() ? (int) $producto->piezas_por_juego : 0;
        $despuesD = (int) ($data['piezas_por_juego'] ?? 0);

        // Piezas propias de cada opción, antes y después. Sin juego no hay
        // ninguna; igual al del producto es lo mismo que no tener.
        $pedidas = $data['piezas_opciones'] ?? [];
        foreach (array_keys($pedidas) as $cfgId) {
            if (! $configs->has((int) $cfgId)) {
                return response()->json(['message' => 'Esa opción no es de este producto.'], 422);
            }
        }
        $antesO = $despuesO = [];
        foreach ($configs as $c) {
            $antesO[$c->id] = $antesD ? (int) $c->piezas_por_juego : 0;
            $nuevo = array_key_exists($c->id, $pedidas) ? (int) $pedidas[$c->id] : (int) $c->piezas_por_juego;
            $despuesO[$c->id] = ($despuesD && $nuevo > 1 && $nuevo !== $despuesD) ? $nuevo : 0;
        }

        // Una opción con su propio número reparte el stock del producto. Si
        // el producto tiene otro tipo de variante más, ese mismo stock está
        // repartido de dos maneras y no hay cómo saber cuántas piezas trae
        // cada unidad.
        $conPropias = collect($despuesO)->filter()->keys()->merge(collect($antesO)->filter()->keys());
        if ($conPropias->isNotEmpty() && $configs->pluck('tipo_variante_id')->unique()->count() > 1) {
            return response()->json([
                'message' => 'Este producto tiene más de un tipo de variante: las piezas por opción solo se pueden poner '
                    . 'cuando tiene uno solo. Usa el mismo número para todo el producto, o sepáralo en dos productos.',
            ], 422);
        }

        // Piezas por unidad guardada, antes y después: 1 sin juego.
        $nAntes   = fn (?int $cfg) => ! $antesD   ? 1 : (($cfg && ! empty($antesO[$cfg]))   ? $antesO[$cfg]   : $antesD);
        $nDespues = fn (?int $cfg) => ! $despuesD ? 1 : (($cfg && ! empty($despuesO[$cfg])) ? $despuesO[$cfg] : $despuesD);

        $convertir = ! empty($data['convertir_stock'])
            && ($nAntes(null) !== $nDespues(null)
                || $configs->keys()->contains(fn ($c) => $nAntes($c) !== $nDespues($c)));

        $plan = [];
        if ($convertir) {
            if ($error = $this->bloqueosParaConvertir($producto->id)) {
                return response()->json(['message' => $error], 422);
            }
            $plan = $this->planDeConversion($producto->id, $configs, $nAntes, $nDespues);
            if (is_string($plan)) {
                return response()->json(['message' => $plan], 422);
            }
        }

        DB::transaction(function () use ($producto, $data, $despuesD, $despuesO, $plan, $request) {
            $producto->update([
                'piezas_por_juego' => $despuesD ?: null,
                'precio_pieza'     => $despuesD ? ($data['precio_pieza'] ?? null) : null,
            ]);
            foreach ($despuesO as $cfgId => $n) {
                DB::table('producto_variante_configs')->where('id', $cfgId)
                    ->update(['piezas_por_juego' => $n ?: null]);
            }

            foreach ($plan as $p) {
                DB::table($p['tabla'])->where('id', $p['id'])->update($p['cambios']);

                // El historial tiene que cuadrar con el número nuevo.
                if ($p['tabla'] === 'inventario' && $p['antes'] !== $p['despues']) {
                    InventarioMovimiento::create([
                        'producto_id' => $producto->id,
                        'tienda_id'   => $p['tienda_id'],
                        'tipo'        => $p['despues'] > $p['antes'] ? 'entrada' : 'salida',
                        'cantidad'    => abs($p['despues'] - $p['antes']),
                        'motivo'      => $despuesD
                            ? "Se cuenta por piezas (juego de {$despuesD}): {$p['antes']} → {$p['despues']}"
                            : "Ya no se vende en juego: el stock vuelve a contarse en juegos ({$p['antes']} piezas → {$p['despues']})",
                        'usuario_id'  => $request->user()->id,
                    ]);
                }
            }
        });

        return response()->json([
            ...$producto->fresh()->toArray(),
            'piezas_opciones' => DB::table('producto_variante_configs')
                ->where('producto_id', $producto->id)->whereNotNull('piezas_por_juego')
                ->pluck('piezas_por_juego', 'id'),
        ]);
    }

    /** Por qué no se puede convertir ahora, o null si se puede. */
    private function bloqueosParaConvertir(int $productoId): ?string
    {
        $apartadas = (int) DB::table('inventario')->where('producto_id', $productoId)->sum('cantidad_reservada');
        if ($apartadas > 0) {
            return "Hay {$apartadas} apartada(s) por órdenes o surtidos pendientes. "
                . 'Entrégalas o cancélalas antes de convertir el stock, o guárdalo sin convertir.';
        }

        $enCamino = DB::table('traslado_items as ti')
            ->join('traslados as t', 't.id', '=', 'ti.traslado_id')
            ->where('ti.producto_id', $productoId)
            ->whereNotIn('t.estado', ['completado', 'rechazado'])
            ->exists()
            || DB::table('surtido_items as si')
                ->join('surtido_tiendas as st', 'st.id', '=', 'si.surtido_tienda_id')
                ->where('si.producto_id', $productoId)
                ->where('st.estado', 'pendiente')
                ->exists();

        return $enCamino
            ? 'Hay un traslado o surtido de este producto todavía sin recibir. Cuando llegue se puede convertir el stock.'
            : null;
    }

    /**
     * Cómo queda cada fila de stock del producto, en todas las tiendas, o un
     * mensaje si algún número no se puede convertir exacto (volver a juegos
     * con media pareja en la tienda, por ejemplo).
     *
     * @return array<int, array{tabla:string,id:int,tienda_id:int,antes:int,despues:int,cambios:array}>|string
     */
    private function planDeConversion(int $productoId, $configs, \Closure $nAntes, \Closure $nDespues): array|string
    {
        $tiendas = DB::table('tiendas')->pluck('nombre', 'id');
        $error   = null;

        // x unidades guardadas con a piezas cada una → con d piezas cada una.
        $conv = function (int $x, int $a, int $d, $tiendaId) use (&$error, $tiendas) {
            if ($x * $d % $a !== 0 && ! $error) {
                $error = 'En ' . ($tiendas[$tiendaId] ?? 'una tienda') . " hay {$x} pieza(s): no son juegos completos de {$a}. "
                    . 'Guárdalo sin convertir (el stock queda como está, contado por piezas) o ajusta ese stock primero.';
            }
            return intdiv($x * $d, $a);
        };
        $min = fn (int $x, int $a, int $d) => (int) ceil($x * $d / $a);

        $porConfig = DB::table('inventario_variante_configs')
            ->whereIn('config_id', $configs->keys())
            ->get(['id', 'config_id', 'tienda_id', 'cantidad_disponible']);
        $combos = DB::table('inventario_variante_combinaciones as x')
            ->join('producto_variantes as pv', 'pv.id', '=', 'x.variante_id')
            ->where('pv.producto_id', $productoId)
            ->get(['x.id', 'x.variante_id', 'x.config_id', 'x.tienda_id', 'x.cantidad_disponible']);
        $porTela = DB::table('inventario_variantes as x')
            ->join('producto_variantes as pv', 'pv.id', '=', 'x.variante_id')
            ->where('pv.producto_id', $productoId)
            ->get(['x.id', 'x.variante_id', 'x.tienda_id', 'x.cantidad_disponible', 'x.stock_minimo']);
        $totales = DB::table('inventario')->where('producto_id', $productoId)
            ->get(['id', 'tienda_id', 'cantidad_disponible', 'stock_minimo']);

        $aD = $nAntes(null);
        $dD = $nDespues(null);
        $plan = [];
        $fila = fn ($tabla, $f, $antes, $despues, $extra = []) => [
            'tabla' => $tabla, 'id' => $f->id, 'tienda_id' => (int) $f->tienda_id,
            'antes' => $antes, 'despues' => $despues,
            'cambios' => ['cantidad_disponible' => $despues, ...$extra],
        ];

        foreach ($porConfig as $f) {
            $q = (int) $f->cantidad_disponible;
            $plan[] = $fila('inventario_variante_configs', $f, $q,
                $conv($q, $nAntes((int) $f->config_id), $nDespues((int) $f->config_id), $f->tienda_id));
        }
        foreach ($combos as $f) {
            $q = (int) $f->cantidad_disponible;
            $plan[] = $fila('inventario_variante_combinaciones', $f, $q,
                $conv($q, $nAntes((int) $f->config_id), $nDespues((int) $f->config_id), $f->tienda_id));
        }

        // Un total = lo de cada opción convertido con lo suyo + lo que no
        // está en ninguna, con el número del producto.
        $rearmar = function (int $total, $partes, $tiendaId) use ($conv, $nAntes, $nDespues, $aD, $dD) {
            $enPartes = 0;
            $nuevo    = 0;
            foreach ($partes as $p) {
                $q = (int) $p->cantidad_disponible;
                $enPartes += $q;
                $nuevo    += $conv($q, $nAntes((int) $p->config_id), $nDespues((int) $p->config_id), $tiendaId);
            }
            return $nuevo + $conv(max(0, $total - $enPartes), $aD, $dD, $tiendaId);
        };

        foreach ($porTela as $f) {
            $q = (int) $f->cantidad_disponible;
            $partes = $combos->where('variante_id', $f->variante_id)->where('tienda_id', $f->tienda_id);
            $plan[] = $fila('inventario_variantes', $f, $q, $rearmar($q, $partes, $f->tienda_id),
                ['stock_minimo' => $min((int) $f->stock_minimo, $aD, $dD)]);
        }
        foreach ($totales as $f) {
            $q = (int) $f->cantidad_disponible;
            $partes = $porConfig->where('tienda_id', $f->tienda_id);
            $plan[] = $fila('inventario', $f, $q, $rearmar($q, $partes, $f->tienda_id),
                ['stock_minimo' => $min((int) $f->stock_minimo, $aD, $dD)]);
        }

        return $error ?? $plan;
    }

    /**
     * DELETE /api/productos/{id}
     * Solo supervisores. Desactiva el producto (activo=false) para preservar historial.
     */
    public function destroy(int $id)
    {
        $producto = Producto::findOrFail($id);
        $producto->update(['activo' => false]);
        return response()->json(['ok' => true]);
    }
}
