<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use App\Models\ProductoVariante;
use App\Models\ProductoVarianteConfig;
use App\Models\Promocion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\Esquema;
use Illuminate\Validation\Rule;

/**
 * Promociones por tienda (Inventario → Promociones). Las programa un
 * supervisor; cualquiera que venda lee las que corren en su tienda para que
 * Nueva orden ponga el precio rebajado sola.
 *
 * Una promoción no se borra si ya se vendió algo con ella: se apaga (las
 * órdenes la nombran). Ver la migración promociones_por_tienda.
 */
class PromocionController extends Controller
{
    /** GET /api/promociones — todas, con su estado. Supervisor. */
    public function index()
    {
        $hoy = Promocion::hoy();
        $promos = Promocion::with(['producto:id,nombre,categoria,precio_base,foto_url', 'config.opcion', 'variante', 'tiendas:id,nombre'])
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
     * tienda. Lo justo para calcular el precio en Nueva orden.
     */
    public function vigentes(Request $request)
    {
        $request->validate(['tienda_id' => 'required|integer']);
        $promos = Promocion::vigentes()->enTienda((int) $request->query('tienda_id'))
            ->with(['producto:id,precio_base', 'config.opcion', 'variante'])
            ->get();

        return response()->json($promos->map(fn (Promocion $p) => [
            'id'          => $p->id,
            'nombre'      => $p->nombre,
            'producto_id' => (int) $p->producto_id,
            'config_id'   => $p->config_id ? (int) $p->config_id : null,
            'variante_id' => $p->variante_id ? (int) $p->variante_id : null,
            'version'     => $p->textoVersion(),
            'tipo'        => $p->tipo,
            'valor'       => (float) $p->valor,
            'fecha_fin'   => $p->fecha_fin->toDateString(),
        ])->values());
    }

    /** POST /api/promociones */
    public function store(Request $request)
    {
        $data = $this->validar($request);
        if ($data['fecha_fin'] < Promocion::hoy()) {
            return response()->json(['message' => 'La fecha final ya pasó: la promoción no alcanzaría a correr ni un día.'], 422);
        }
        if ($error = $this->problema($data)) return response()->json(['message' => $error], 422);

        $promo = DB::transaction(function () use ($data, $request) {
            $p = Promocion::create(collect($data)->except('tiendas')->all() + [
                'activa'        => true,
                'creado_por_id' => $request->user()->id,
            ]);
            $p->tiendas()->sync($data['tiendas']);
            return $p;
        });

        return response()->json($this->comoJson($promo->fresh(['producto', 'config.opcion', 'variante', 'tiendas'])), 201);
    }

    /** PATCH /api/promociones/{id} — cambiar cualquier cosa, o apagarla/encenderla (`activa`). */
    public function update(Request $request, int $id)
    {
        $promo = Promocion::with('tiendas:id')->findOrFail($id);

        // Solo apagar o encender: no hace falta mandar todo lo demás.
        if ($request->has('activa') && count($request->all()) === 1) {
            $activa = $request->validate(['activa' => 'required|boolean'])['activa'];
            if ($activa) {
                $datos = $this->datosDe($promo);
                if ($error = $this->choca($datos, $promo->id)) return response()->json(['message' => $error], 422);
            }
            $promo->update(['activa' => $activa]);
            return response()->json($this->comoJson($promo->fresh(['producto', 'config.opcion', 'variante', 'tiendas'])));
        }

        $data = $this->validar($request);
        if ($error = $this->problema($data + ['activa' => $promo->activa], $promo->id)) {
            return response()->json(['message' => $error], 422);
        }

        DB::transaction(function () use ($promo, $data) {
            $promo->update(collect($data)->except('tiendas')->all());
            $promo->tiendas()->sync($data['tiendas']);
        });

        return response()->json($this->comoJson($promo->fresh(['producto', 'config.opcion', 'variante', 'tiendas'])));
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

    private function validar(Request $request): array
    {
        $data = $request->validate([
            'nombre'       => 'nullable|string|max:120',
            'producto_id'  => 'required|integer|exists:productos,id',
            'config_id'    => 'nullable|integer|exists:producto_variante_configs,id',
            'variante_id'  => 'nullable|integer|exists:producto_variantes,id',
            'tipo'         => ['required', Rule::in(Promocion::TIPOS)],
            'valor'        => 'required|numeric|gt:0',
            'fecha_inicio' => 'required|date_format:Y-m-d',
            'fecha_fin'    => 'required|date_format:Y-m-d|after_or_equal:fecha_inicio',
            'tiendas'      => 'required|array|min:1',
            'tiendas.*'    => 'integer|distinct|exists:tiendas,id',
        ], [
            'fecha_fin.after_or_equal' => 'La fecha final no puede ser antes de la inicial.',
            'tiendas.required'         => 'Escoge al menos una tienda.',
            'tiendas.min'              => 'Escoge al menos una tienda.',
            'valor.gt'                 => 'Escribe cuánto se rebaja.',
        ]);
        $data['nombre']      = trim((string) ($data['nombre'] ?? '')) ?: null;
        $data['config_id']   = $data['config_id'] ?? null;
        $data['variante_id'] = $data['variante_id'] ?? null;
        return $data;
    }

    /** Lo que no cuadra con el producto, la plata o las otras promociones; null si todo bien. */
    private function problema(array $d, ?int $ignorarId = null): ?string
    {
        if ($d['config_id'] && $d['variante_id']) {
            return 'Escoge una sola versión: la opción (medida…) o la tela, no las dos.';
        }
        $producto = Producto::find($d['producto_id']);
        if ($d['config_id'] && (int) ProductoVarianteConfig::whereKey($d['config_id'])->value('producto_id') !== (int) $producto->id) {
            return 'Esa opción no es de este producto.';
        }
        if ($d['variante_id'] && (int) ProductoVariante::whereKey($d['variante_id'])->value('producto_id') !== (int) $producto->id) {
            return 'Esa tela no es de este producto.';
        }

        // Un precio final para "todas las versiones" deja la cama de 1.00 y la
        // de 2.00 en lo mismo: casi siempre es un error de dedo.
        if ($d['tipo'] === 'precio' && ! $d['config_id'] && ! $d['variante_id'] && $this->tieneVersionesConPrecio($producto)) {
            return 'Este producto tiene versiones con precios distintos: para dejar un precio final escoge cuál, o usa % o $ de rebaja.';
        }

        $valor = (float) $d['valor'];
        if ($d['tipo'] === 'porcentaje' && $valor >= 100) {
            return 'El porcentaje tiene que ser menor que 100 %.';
        }
        $tmp = new Promocion(['producto_id' => $producto->id, 'config_id' => $d['config_id'], 'variante_id' => $d['variante_id']]);
        $lista = $tmp->precioDeLista();
        if ($lista > 0 && $d['tipo'] === 'monto' && $valor >= $lista) {
            return 'La rebaja no puede ser igual o mayor que el precio ($' . number_format($lista, 0, ',', '.') . ').';
        }
        if ($lista > 0 && $d['tipo'] === 'precio' && $valor >= $lista) {
            return 'El precio de promoción tiene que ser menor que el de lista ($' . number_format($lista, 0, ',', '.') . ').';
        }

        if (($d['activa'] ?? true) && ($error = $this->choca($d, $ignorarId))) return $error;
        return null;
    }

    /**
     * Dos promociones a la misma cosa, en la misma tienda y en días que se
     * cruzan: ¿cuál vale? Mejor no dejarlo. (Una del producto y otra de la
     * medida sí pueden ir juntas: gana la de la medida.)
     */
    private function choca(array $d, ?int $ignorarId = null): ?string
    {
        $otra = Promocion::where('activa', true)
            ->where('producto_id', $d['producto_id'])
            ->when($d['config_id'], fn ($q) => $q->where('config_id', $d['config_id']), fn ($q) => $q->whereNull('config_id'))
            ->when($d['variante_id'], fn ($q) => $q->where('variante_id', $d['variante_id']), fn ($q) => $q->whereNull('variante_id'))
            ->whereDate('fecha_inicio', '<=', $d['fecha_fin'])
            ->whereDate('fecha_fin', '>=', $d['fecha_inicio'])
            ->whereHas('tiendas', fn ($t) => $t->whereIn('tiendas.id', $d['tiendas']))
            ->when($ignorarId, fn ($q) => $q->where('id', '!=', $ignorarId))
            ->with('tiendas:id,nombre')
            ->first();
        if (! $otra) return null;

        $tiendas = $otra->tiendas->whereIn('id', $d['tiendas'])->pluck('nombre')->implode(', ');
        return "Ya hay una promoción para lo mismo en {$tiendas} del {$otra->fecha_inicio->format('d/m/Y')} al "
            . "{$otra->fecha_fin->format('d/m/Y')}. Cámbiale las fechas o apágala primero.";
    }

    private function tieneVersionesConPrecio(Producto $producto): bool
    {
        return ProductoVarianteConfig::where('producto_id', $producto->id)->where('precio_adicional', '>', 0)->exists()
            || ProductoVariante::where('producto_id', $producto->id)->where('activo', true)->whereNotNull('precio_variante')->exists();
    }

    private function datosDe(Promocion $p): array
    {
        return [
            'producto_id'  => (int) $p->producto_id,
            'config_id'    => $p->config_id,
            'variante_id'  => $p->variante_id,
            'fecha_inicio' => $p->fecha_inicio->toDateString(),
            'fecha_fin'    => $p->fecha_fin->toDateString(),
            'tiendas'      => $p->tiendas->pluck('id')->all(),
        ];
    }

    private function comoJson(Promocion $p, ?string $hoy = null): array
    {
        $lista = $p->precioDeLista();
        return [
            'id'            => $p->id,
            'nombre'        => $p->nombre,
            'producto_id'   => (int) $p->producto_id,
            'producto'      => $p->producto ? [
                'id' => $p->producto->id, 'nombre' => $p->producto->nombre,
                'categoria' => $p->producto->categoria, 'foto_url' => $p->producto->foto_url,
            ] : null,
            'config_id'     => $p->config_id ? (int) $p->config_id : null,
            'variante_id'   => $p->variante_id ? (int) $p->variante_id : null,
            'version'       => $p->textoVersion(),
            'tipo'          => $p->tipo,
            'valor'         => (float) $p->valor,
            'precio_lista'  => $lista,
            'precio_promo'  => $p->precioCon($lista),
            'fecha_inicio'  => $p->fecha_inicio->toDateString(),
            'fecha_fin'     => $p->fecha_fin->toDateString(),
            'activa'        => (bool) $p->activa,
            'estado'        => $p->estado($hoy),
            'tiendas'       => $p->tiendas->map(fn ($t) => ['id' => $t->id, 'nombre' => $t->nombre])->values(),
        ];
    }
}
