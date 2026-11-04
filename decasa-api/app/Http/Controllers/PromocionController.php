<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use App\Models\ProductoVariante;
use App\Models\ProductoVarianteConfig;
use App\Models\Promocion;
use App\Models\PromocionProducto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\Esquema;
use Illuminate\Validation\Rule;

/**
 * Promociones por tienda (Inventario → Promociones). Las programa un
 * supervisor; cualquiera que venda lee las que corren en su tienda para que
 * Nueva orden ponga el precio rebajado sola.
 *
 * Una promoción puede llevar uno o varios productos con las mismas reglas
 * (2026-10-28), y de cada uno todas sus versiones, solo algunas o ninguna.
 *
 * Una promoción no se borra si ya se vendió algo con ella: se apaga (las
 * órdenes la nombran). Ver la migración promociones_por_tienda.
 */
class PromocionController extends Controller
{
    /** Lo que se pregunta por cada producto: ¿a cuáles de sus versiones? */
    public const ALCANCES = ['todas', 'algunas', 'sin_versiones'];

    /** GET /api/promociones — todas, con su estado. Supervisor. */
    public function index()
    {
        $hoy = Promocion::hoy();
        $promos = Promocion::with(Promocion::RELACIONES)
            ->orderByDesc('fecha_fin')->orderByDesc('id')
            ->get();

        $usos = Esquema::columna('orden_items', 'promocion_id')
            ? DB::table('orden_items')->whereIn('promocion_id', $promos->pluck('id'))
                ->selectRaw('promocion_id, COUNT(*) AS n')->groupBy('promocion_id')->pluck('n', 'promocion_id')
            : collect();

        return response()->json($promos->map(fn (Promocion $p) => $this->comoJson($p, $hoy) + [
            'usos' => (int) ($usos[$p->id] ?? 0),
        ])->values());
    }

    /**
     * GET /api/promociones/vigentes?tienda_id=X — las que corren hoy en esa
     * tienda. Lo justo para calcular el precio en Nueva orden: una fila por
     * producto o versión (una promoción de 5 productos sale 5 veces, con el
     * mismo `id`), como cuando cada promoción era de un solo producto.
     */
    public function vigentes(Request $request)
    {
        $request->validate(['tienda_id' => 'required|integer']);
        $promos = Promocion::vigentes()->enTienda((int) $request->query('tienda_id'))
            ->with(['objetivos.config.opcion', 'objetivos.variante'])
            ->get();

        return response()->json($promos->flatMap(fn (Promocion $p) => $p->objetivos->map(fn (PromocionProducto $o) => [
            'id'            => $p->id,
            'clave'         => $p->id . '-' . $o->id,
            'nombre'        => $p->nombre,
            'producto_id'   => (int) $o->producto_id,
            'config_id'     => $o->config_id ? (int) $o->config_id : null,
            'variante_id'   => $o->variante_id ? (int) $o->variante_id : null,
            'sin_versiones' => (bool) $o->sin_versiones,
            'version'       => $o->sin_versiones ? 'Sin opciones' : $o->textoVersion(),
            'tipo'          => $p->tipo,
            'valor'         => (float) $p->valor,
            'fecha_fin'     => $p->fecha_fin->toDateString(),
        ]))->values());
    }

    /** POST /api/promociones */
    public function store(Request $request)
    {
        $data = $this->validar($request);
        if ($data['fecha_fin'] < Promocion::hoy()) {
            return response()->json(['message' => 'La fecha final ya pasó: la promoción no alcanzaría a correr ni un día.'], 422);
        }
        $objetivos = $this->objetivosDe($data['productos']);
        if (is_string($objetivos)) return response()->json(['message' => $objetivos], 422);
        if ($error = $this->problema($data, $objetivos)) return response()->json(['message' => $error], 422);

        $promo = DB::transaction(function () use ($data, $objetivos, $request) {
            $p = Promocion::create($this->columnas($data, $objetivos) + [
                'activa'        => true,
                'creado_por_id' => $request->user()->id,
            ]);
            $p->tiendas()->sync($data['tiendas']);
            $this->guardarObjetivos($p, $objetivos);
            return $p;
        });

        return response()->json($this->comoJson($promo->fresh(Promocion::RELACIONES)), 201);
    }

    /** PATCH /api/promociones/{id} — cambiar cualquier cosa, o apagarla/encenderla (`activa`). */
    public function update(Request $request, int $id)
    {
        $promo = Promocion::with(['tiendas:id', 'objetivos'])->findOrFail($id);

        // Solo apagar o encender: no hace falta mandar todo lo demás.
        if ($request->has('activa') && count($request->all()) === 1) {
            $activa = $request->validate(['activa' => 'required|boolean'])['activa'];
            if ($activa) {
                $objetivos = $promo->objetivos->map(fn (PromocionProducto $o) => [
                    'producto_id'   => (int) $o->producto_id,
                    'config_id'     => $o->config_id,
                    'variante_id'   => $o->variante_id,
                    'sin_versiones' => (bool) $o->sin_versiones,
                ])->all();
                if ($error = $this->choca($this->datosDe($promo), $objetivos, $promo->id)) {
                    return response()->json(['message' => $error], 422);
                }
            }
            $promo->update(['activa' => $activa]);
            return response()->json($this->comoJson($promo->fresh(Promocion::RELACIONES)));
        }

        $data = $this->validar($request);
        $objetivos = $this->objetivosDe($data['productos']);
        if (is_string($objetivos)) return response()->json(['message' => $objetivos], 422);
        if ($error = $this->problema($data + ['activa' => $promo->activa], $objetivos, $promo->id)) {
            return response()->json(['message' => $error], 422);
        }

        DB::transaction(function () use ($promo, $data, $objetivos) {
            $promo->update($this->columnas($data, $objetivos));
            $promo->tiendas()->sync($data['tiendas']);
            $this->guardarObjetivos($promo, $objetivos);
        });

        return response()->json($this->comoJson($promo->fresh(Promocion::RELACIONES)));
    }

    /** DELETE /api/promociones/{id} — solo si no se ha vendido nada con ella. */
    public function destroy(int $id)
    {
        $promo = Promocion::findOrFail($id);
        $usada = Esquema::columna('orden_items', 'promocion_id')
            && DB::table('orden_items')->where('promocion_id', $promo->id)->exists();
        if ($usada) {
            return response()->json([
                'message' => 'Ya hay órdenes vendidas con esta promoción: no se borra para no perder ese dato. Apágala.',
            ], 422);
        }
        $promo->delete();
        return response()->json(['ok' => true]);
    }

    // ── Reglas ────────────────────────────────────────────────────────────────

    /**
     * Lo que manda la pantalla. `productos` es la forma de ahora (uno o
     * varios, cada uno con su alcance); `producto_id` + `config_id` o
     * `variante_id` es la de antes (un producto, a lo más una versión) y se
     * sigue aceptando.
     */
    private function validar(Request $request): array
    {
        $data = $request->validate([
            'nombre'                   => 'nullable|string|max:120',
            'productos'                => 'required_without:producto_id|array|min:1|max:100',
            'productos.*.producto_id'  => 'required|integer|distinct|exists:productos,id',
            'productos.*.alcance'      => ['nullable', Rule::in(self::ALCANCES)],
            'productos.*.config_ids'   => 'nullable|array',
            'productos.*.config_ids.*' => 'integer|exists:producto_variante_configs,id',
            'productos.*.variante_ids'   => 'nullable|array',
            'productos.*.variante_ids.*' => 'integer|exists:producto_variantes,id',
            'producto_id'              => 'required_without:productos|nullable|integer|exists:productos,id',
            'config_id'                => 'nullable|integer|exists:producto_variante_configs,id',
            'variante_id'              => 'nullable|integer|exists:producto_variantes,id',
            'tipo'                     => ['required', Rule::in(Promocion::TIPOS)],
            'valor'                    => 'required|numeric|gt:0',
            'fecha_inicio'             => 'required|date_format:Y-m-d',
            'fecha_fin'                => 'required|date_format:Y-m-d|after_or_equal:fecha_inicio',
            'tiendas'                  => 'required|array|min:1',
            'tiendas.*'                => 'integer|distinct|exists:tiendas,id',
        ], [
            'fecha_fin.after_or_equal'        => 'La fecha final no puede ser antes de la inicial.',
            'tiendas.required'                => 'Escoge al menos una tienda.',
            'tiendas.min'                     => 'Escoge al menos una tienda.',
            'valor.gt'                        => 'Escribe cuánto se rebaja.',
            'productos.required_without'      => 'Escoge al menos un producto.',
            'productos.max'                   => 'Máximo 100 productos por promoción.',
            'productos.*.producto_id.distinct' => 'Un producto está dos veces en la lista.',
        ]);
        $data['nombre'] = trim((string) ($data['nombre'] ?? '')) ?: null;

        if (empty($data['productos'])) {
            $config   = $data['config_id'] ?? null;
            $variante = $data['variante_id'] ?? null;
            $data['productos'] = [[
                'producto_id'  => (int) $data['producto_id'],
                'alcance'      => ($config || $variante) ? 'algunas' : 'todas',
                'config_ids'   => $config ? [(int) $config] : [],
                'variante_ids' => $variante ? [(int) $variante] : [],
                // La forma de antes no dejaba las dos: se sigue frenando abajo.
                '_las_dos'     => $config && $variante,
            ]];
        }
        return $data;
    }

    /**
     * Las filas de `promocion_productos` que salen de lo que se escogió, o el
     * error si algo no cuadra con los productos.
     */
    private function objetivosDe(array $productos): array|string
    {
        $ids       = collect($productos)->pluck('producto_id')->map(fn ($id) => (int) $id);
        $nombres   = Producto::whereIn('id', $ids)->pluck('nombre', 'id');
        $configIds = collect($productos)->flatMap(fn ($p) => $p['config_ids'] ?? [])->map(fn ($id) => (int) $id);
        $varIds    = collect($productos)->flatMap(fn ($p) => $p['variante_ids'] ?? [])->map(fn ($id) => (int) $id);
        $deQuienConfig   = ProductoVarianteConfig::whereIn('id', $configIds)->pluck('producto_id', 'id');
        $deQuienVariante = ProductoVariante::whereIn('id', $varIds)->pluck('producto_id', 'id');

        $filas = [];
        foreach ($productos as $p) {
            $productoId = (int) $p['producto_id'];
            $nombre     = $nombres[$productoId] ?? 'el producto';
            $configs    = collect($p['config_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
            $variantes  = collect($p['variante_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
            $alcance    = $p['alcance'] ?? (($configs->isNotEmpty() || $variantes->isNotEmpty()) ? 'algunas' : 'todas');

            if (! empty($p['_las_dos'])) {
                return 'Escoge una sola versión: la opción (medida…) o la tela, no las dos.';
            }
            foreach ($configs as $c) {
                if ((int) ($deQuienConfig[$c] ?? 0) !== $productoId) return "Esa opción no es de {$nombre}.";
            }
            foreach ($variantes as $v) {
                if ((int) ($deQuienVariante[$v] ?? 0) !== $productoId) return "Esa tela no es de {$nombre}.";
            }

            if ($alcance === 'algunas') {
                if ($configs->isEmpty() && $variantes->isEmpty()) {
                    return "De {$nombre} escoge al menos una versión, o márcalo con todas sus versiones.";
                }
                foreach ($configs as $c) $filas[] = $this->fila($productoId, $c, null, false);
                foreach ($variantes as $v) $filas[] = $this->fila($productoId, null, $v, false);
            } else {
                $filas[] = $this->fila($productoId, null, null, $alcance === 'sin_versiones');
            }
        }
        return $filas;
    }

    private function fila(int $productoId, ?int $configId, ?int $varianteId, bool $sinVersiones): array
    {
        return [
            'producto_id'   => $productoId,
            'config_id'     => $configId,
            'variante_id'   => $varianteId,
            'sin_versiones' => $sinVersiones,
        ];
    }

    /** Lo que no cuadra con la plata o las otras promociones; null si todo bien. */
    private function problema(array $d, array $objetivos, ?int $ignorarId = null): ?string
    {
        $valor = (float) $d['valor'];
        if ($d['tipo'] === 'porcentaje' && $valor >= 100) {
            return 'El porcentaje tiene que ser menor que 100 %.';
        }

        $varios = collect($objetivos)->pluck('producto_id')->unique()->count() > 1;
        foreach ($objetivos as $o) {
            $fila     = new PromocionProducto($o);
            $nombre   = $fila->producto?->nombre ?? 'el producto';
            $deQuien  = $varios ? " de {$nombre}" : '';

            // Un precio final para "todas las versiones" deja la cama de 1.00 y la
            // de 2.00 en lo mismo: casi siempre es un error de dedo.
            if ($d['tipo'] === 'precio' && ! $o['config_id'] && ! $o['variante_id'] && ! $o['sin_versiones']
                && $this->tieneVersionesConPrecio($o['producto_id'])) {
                return ($varios ? "{$nombre} tiene" : 'Este producto tiene')
                    . ' versiones con precios distintos: para dejar un precio final escoge cuáles, o usa % o $ de rebaja.';
            }

            $lista = $fila->precioDeLista();
            if ($lista > 0 && $d['tipo'] === 'monto' && $valor >= $lista) {
                return "La rebaja no puede ser igual o mayor que el precio{$deQuien} ($" . number_format($lista, 0, ',', '.') . ').';
            }
            if ($lista > 0 && $d['tipo'] === 'precio' && $valor >= $lista) {
                return "El precio de promoción tiene que ser menor que el de lista{$deQuien} ($" . number_format($lista, 0, ',', '.') . ').';
            }
        }

        if (($d['activa'] ?? true) && ($error = $this->choca($d, $objetivos, $ignorarId))) return $error;
        return null;
    }

    /**
     * Dos promociones a la misma cosa, en la misma tienda y en días que se
     * cruzan: ¿cuál vale? Mejor no dejarlo. (Una del producto y otra de la
     * medida sí pueden ir juntas: gana la de la medida.)
     */
    private function choca(array $d, array $objetivos, ?int $ignorarId = null): ?string
    {
        $llaves = collect($objetivos)->mapWithKeys(fn ($o) => [
            PromocionProducto::llaveDe((int) $o['producto_id'], $o['config_id'], $o['variante_id'], (bool) $o['sin_versiones']) => true,
        ]);
        $otras = Promocion::where('activa', true)
            ->whereDate('fecha_inicio', '<=', $d['fecha_fin'])
            ->whereDate('fecha_fin', '>=', $d['fecha_inicio'])
            ->whereHas('tiendas', fn ($t) => $t->whereIn('tiendas.id', $d['tiendas']))
            ->whereHas('objetivos', fn ($q) => $q->whereIn('producto_id', collect($objetivos)->pluck('producto_id')->unique()))
            ->when($ignorarId, fn ($q) => $q->where('id', '!=', $ignorarId))
            ->with(['tiendas:id,nombre', 'objetivos.producto:id,nombre', 'objetivos.config.opcion', 'objetivos.variante'])
            ->get();

        foreach ($otras as $otra) {
            $repetida = $otra->objetivos->first(fn (PromocionProducto $o) => $llaves->has($o->llave()));
            if (! $repetida) continue;

            $que = $repetida->producto?->nombre ?? 'lo mismo';
            if ($v = $repetida->sin_versiones ? 'sin opciones' : $repetida->textoVersion()) $que .= " ({$v})";
            $tiendas = $otra->tiendas->whereIn('id', $d['tiendas'])->pluck('nombre')->implode(', ');
            return "Ya hay una promoción para {$que} en {$tiendas} del {$otra->fecha_inicio->format('d/m/Y')} al "
                . "{$otra->fecha_fin->format('d/m/Y')}. Cámbiale las fechas, quítalo de esta o apaga la otra primero.";
        }
        return null;
    }

    private function tieneVersionesConPrecio(int $productoId): bool
    {
        return ProductoVarianteConfig::where('producto_id', $productoId)->where('precio_adicional', '>', 0)->exists()
            || ProductoVariante::where('producto_id', $productoId)->where('activo', true)->whereNotNull('precio_variante')->exists();
    }

    /** Las columnas de `promociones`; las viejas de producto llevan el primero (ver la migración). */
    private function columnas(array $data, array $objetivos): array
    {
        $primero = $objetivos[0];
        return collect($data)->only(['nombre', 'tipo', 'valor', 'fecha_inicio', 'fecha_fin'])->all() + [
            'producto_id' => $primero['producto_id'],
            'config_id'   => $primero['config_id'],
            'variante_id' => $primero['variante_id'],
        ];
    }

    /** Las filas no las nombra nadie más (las órdenes guardan la promoción): se reponen todas. */
    private function guardarObjetivos(Promocion $p, array $objetivos): void
    {
        $p->objetivos()->delete();
        $p->objetivos()->createMany($objetivos);
    }

    private function datosDe(Promocion $p): array
    {
        return [
            'fecha_inicio' => $p->fecha_inicio->toDateString(),
            'fecha_fin'    => $p->fecha_fin->toDateString(),
            'tiendas'      => $p->tiendas->pluck('id')->all(),
        ];
    }

    private function comoJson(Promocion $p, ?string $hoy = null): array
    {
        $productos = $p->objetivos->groupBy('producto_id')->map(function ($filas) use ($p) {
            $primera = $filas->first();
            $prod    = $primera->producto;
            $base    = (float) ($prod?->precio_base ?? 0);
            return [
                'producto_id'  => (int) $primera->producto_id,
                'producto'     => $prod ? [
                    'id' => $prod->id, 'nombre' => $prod->nombre, 'categoria' => $prod->categoria,
                    'foto_url' => $prod->foto_url, 'precio_base' => $base,
                ] : null,
                'alcance'      => $primera->alcance(),
                'config_ids'   => $filas->pluck('config_id')->filter()->map(fn ($id) => (int) $id)->values(),
                'variante_ids' => $filas->pluck('variante_id')->filter()->map(fn ($id) => (int) $id)->values(),
                'versiones'    => $filas->filter(fn ($f) => $f->config_id || $f->variante_id)->map(fn ($f) => [
                    'texto'        => $f->textoVersion(),
                    'precio_lista' => $lista = $f->precioDeLista(),
                    'precio_promo' => $p->precioCon($lista),
                ])->values(),
                'precio_lista' => $base,
                'precio_promo' => $p->precioCon($base),
            ];
        })->values();

        // Una sola fila: lo de siempre (producto, versión y cómo queda el precio).
        $unaFila = $p->objetivos->count() === 1 ? $p->objetivos->first() : null;
        $primero = $productos->first();
        $version = null;
        if ($productos->count() === 1) {
            $version = match ($primero['alcance']) {
                'algunas'       => $productos->first()['versiones']->pluck('texto')->filter()->implode(', ') ?: null,
                'sin_versiones' => 'Sin opciones',
                default         => null,
            };
        }
        $lista = $unaFila?->precioDeLista();

        return [
            'id'            => $p->id,
            'nombre'        => $p->nombre,
            'productos'     => $productos,
            'producto_id'   => $primero['producto_id'] ?? null,
            'producto'      => $primero['producto'] ?? null,
            'version'       => $version,
            'tipo'          => $p->tipo,
            'valor'         => (float) $p->valor,
            'precio_lista'  => $lista,
            'precio_promo'  => $lista === null ? null : $p->precioCon($lista),
            'fecha_inicio'  => $p->fecha_inicio->toDateString(),
            'fecha_fin'     => $p->fecha_fin->toDateString(),
            'activa'        => (bool) $p->activa,
            'estado'        => $p->estado($hoy),
            'tiendas'       => $p->tiendas->map(fn ($t) => ['id' => $t->id, 'nombre' => $t->nombre])->values(),
        ];
    }
}
