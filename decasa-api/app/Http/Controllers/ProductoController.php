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
     * Activa, cambia o quita la venta en juego (unas mesas que vienen de a 2).
     *
     * Con el juego activo el stock se cuenta por PIEZAS. Lo que ya estaba
     * cargado casi siempre está contado en juegos ("pongo 1"), así que al
     * activarlo se ofrece multiplicar por N (`convertir_stock`); y al quitarlo,
     * dividir de vuelta. Pasar de juego de 2 a juego de 3 no convierte nada:
     * las piezas que hay son las mismas, solo cambia cómo se agrupan.
     *
     * Convertir no se deja mientras haya algo apartado o en camino: esas
     * órdenes, traslados y surtidos guardaron su cantidad en la unidad vieja,
     * y al moverse después descontarían una cantidad que ya no corresponde.
     */
    public function ventaPorJuego(Request $request, int $id)
    {
        $producto = Producto::findOrFail($id);

        $data = $request->validate([
            'piezas_por_juego' => 'nullable|integer|min:2|max:50',
            'precio_pieza'     => 'nullable|numeric|min:0',
            'convertir_stock'  => 'sometimes|boolean',
        ]);

        $antes     = $producto->seVendeEnJuego() ? (int) $producto->piezas_por_juego : null;
        $despues   = $data['piezas_por_juego'] ?? null;
        // Solo se convierte al entrar o salir del juego, no al cambiar N.
        $convertir = ! empty($data['convertir_stock']) && (bool) $antes !== (bool) $despues;
        $factor    = $despues ?? $antes;   // N con el que se multiplica o divide

        $filas = $convertir ? $this->filasDeStock($producto->id) : collect();

        if ($convertir) {
            $apartadas = $filas->sum(fn ($f) => (int) $f->cantidad_reservada);
            if ($apartadas > 0) {
                return response()->json([
                    'message' => "Hay {$apartadas} apartada(s) por órdenes o surtidos pendientes. "
                        . 'Entrégalas o cancélalas antes de convertir el stock, o actívalo sin convertir.',
                ], 422);
            }

            $enCamino = DB::table('traslado_items as ti')
                ->join('traslados as t', 't.id', '=', 'ti.traslado_id')
                ->where('ti.producto_id', $producto->id)
                ->whereNotIn('t.estado', ['completado', 'rechazado'])
                ->exists()
                || DB::table('surtido_items as si')
                    ->join('surtido_tiendas as st', 'st.id', '=', 'si.surtido_tienda_id')
                    ->where('si.producto_id', $producto->id)
                    ->where('st.estado', 'pendiente')
                    ->exists();
            if ($enCamino) {
                return response()->json([
                    'message' => 'Hay un traslado o surtido de este producto todavía sin recibir. '
                        . 'Cuando llegue se puede convertir el stock.',
                ], 422);
            }

            // Volver a contar en juegos solo si cada número es de juegos
            // completos: media pareja no se puede escribir como juego.
            if (! $despues) {
                $suelta = $filas->first(fn ($f) => (int) $f->cantidad_disponible % $factor !== 0);
                if ($suelta) {
                    return response()->json([
                        'message' => "En {$suelta->tienda} hay {$suelta->cantidad_disponible} pieza(s): no son juegos completos de {$factor}. "
                            . 'Quítalo sin convertir (el stock queda contado por piezas) o ajusta ese stock primero.',
                    ], 422);
                }
            }
        }

        DB::transaction(function () use ($producto, $data, $despues, $convertir, $factor, $filas, $request) {
            $producto->update([
                'piezas_por_juego' => $despues,
                'precio_pieza'     => $despues ? ($data['precio_pieza'] ?? null) : null,
            ]);

            if (! $convertir) return;

            $mult = (bool) $despues;   // true: juegos → piezas; false: piezas → juegos
            $sql  = $mult ? "* {$factor}" : "/ {$factor}";   // al dividir ya se verificó que sea exacto

            foreach ($filas as $f) {
                $cambios = ['cantidad_disponible' => DB::raw("cantidad_disponible {$sql}")];
                if ($f->con_minimo) $cambios['stock_minimo'] = DB::raw("stock_minimo {$sql}");
                DB::table($f->tabla)->where('id', $f->id)->update($cambios);

                // El historial tiene que cuadrar con el número nuevo.
                if ($f->tabla === 'inventario' && (int) $f->cantidad_disponible > 0) {
                    $q     = (int) $f->cantidad_disponible;
                    $nuevo = $mult ? $q * $factor : intdiv($q, $factor);
                    InventarioMovimiento::create([
                        'producto_id' => $producto->id,
                        'tienda_id'   => $f->tienda_id,
                        'tipo'        => $mult ? 'entrada' : 'salida',
                        'cantidad'    => abs($nuevo - $q),
                        'motivo'      => $mult
                            ? "Se vende en juego de {$factor}: el stock pasa a contarse por piezas ({$q} juego(s) = {$nuevo} piezas)"
                            : "Ya no se vende en juego: el stock vuelve a contarse en juegos ({$q} piezas = {$nuevo} juego(s))",
                        'usuario_id'  => $request->user()->id,
                    ]);
                }
            }
        });

        return response()->json($producto->fresh());
    }

    /**
     * Todas las filas de stock del producto, en todas las tiendas: el total,
     * el reparto por variante, por configuración y por combinación. Las
     * cuatro cuentan unidades del mismo producto, así que se convierten juntas.
     */
    private function filasDeStock(int $productoId): \Illuminate\Support\Collection
    {
        $tienda = fn ($q) => $q->leftJoin('tiendas as t', 't.id', '=', 'x.tienda_id');

        return collect()
            ->merge($tienda(DB::table('inventario as x'))
                ->where('x.producto_id', $productoId)
                ->get(['x.id', 'x.tienda_id', 'x.cantidad_disponible', 'x.cantidad_reservada', 't.nombre as tienda'])
                ->map(fn ($f) => (object) [...(array) $f, 'tabla' => 'inventario', 'con_minimo' => true]))
            ->merge($tienda(DB::table('inventario_variantes as x'))
                ->join('producto_variantes as pv', 'pv.id', '=', 'x.variante_id')
                ->where('pv.producto_id', $productoId)
                ->get(['x.id', 'x.tienda_id', 'x.cantidad_disponible', 'x.cantidad_reservada', 't.nombre as tienda'])
                ->map(fn ($f) => (object) [...(array) $f, 'tabla' => 'inventario_variantes', 'con_minimo' => true]))
            ->merge($tienda(DB::table('inventario_variante_configs as x'))
                ->join('producto_variante_configs as pvc', 'pvc.id', '=', 'x.config_id')
                ->where('pvc.producto_id', $productoId)
                ->get(['x.id', 'x.tienda_id', 'x.cantidad_disponible', 'x.cantidad_reservada', 't.nombre as tienda'])
                ->map(fn ($f) => (object) [...(array) $f, 'tabla' => 'inventario_variante_configs', 'con_minimo' => false]))
            ->merge($tienda(DB::table('inventario_variante_combinaciones as x'))
                ->join('producto_variantes as pv', 'pv.id', '=', 'x.variante_id')
                ->where('pv.producto_id', $productoId)
                ->get(['x.id', 'x.tienda_id', 'x.cantidad_disponible', 'x.cantidad_reservada', 't.nombre as tienda'])
                ->map(fn ($f) => (object) [...(array) $f, 'tabla' => 'inventario_variante_combinaciones', 'con_minimo' => false]));
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
