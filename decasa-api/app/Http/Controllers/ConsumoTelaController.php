<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use App\Models\ProductoConsumoTela;
use App\Models\ProductoVarianteConfig;
use App\Models\TelaReserva;
use App\Services\ConsumoTelas;
use Illuminate\Http\Request;

/**
 * Cuánta tela lleva cada producto tapizado, y el interruptor que hace que
 * las ventas la aparten y la descuenten solas. Ver ConsumoTelas.
 */
class ConsumoTelaController extends Controller
{
    /**
     * GET /telas/consumo
     *
     * Los productos tapizados con sus metros: el consumo base y, si el
     * producto tiene medidas configurables, una fila por medida. El color no
     * aparece porque no cambia los metros.
     */
    public function index()
    {
        $productos = Producto::where('activo', true)
            ->where('es_tapizado', true)
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'categoria', 'foto_url']);

        $consumos = ProductoConsumoTela::whereIn('producto_id', $productos->pluck('id'))
            ->get()
            ->groupBy('producto_id');

        $configs = ProductoVarianteConfig::whereIn('producto_id', $productos->pluck('id'))
            ->with(['tipo:id,nombre', 'opcion:id,nombre'])
            ->get()
            ->groupBy('producto_id');

        $lista = $productos->map(function (Producto $p) use ($consumos, $configs) {
            $suyos = $consumos->get($p->id, collect());

            return [
                'id'        => $p->id,
                'nombre'    => $p->nombre,
                'categoria' => $p->categoria,
                'foto_url'  => $p->foto_url,
                'metros'    => self::metrosDe($suyos->firstWhere('config_id', null)),
                'medidas'   => $configs->get($p->id, collect())
                    ->sortBy(fn ($c) => ($c->tipo->nombre ?? '') . ' ' . ($c->opcion->nombre ?? ''))
                    ->map(fn ($c) => [
                        'config_id' => $c->id,
                        'tipo'      => $c->tipo->nombre   ?? null,
                        'nombre'    => $c->opcion->nombre ?? "Opción #{$c->opcion_id}",
                        'metros'    => self::metrosDe($suyos->firstWhere('config_id', $c->id)),
                    ])->values(),
            ];
        });

        return response()->json([
            'activo'    => ConsumoTelas::activo(),
            'productos' => $lista,
            // Para que se vea que la función está haciendo algo: cuántas
            // reservas hay vivas ahora mismo.
            'reservas_vivas' => TelaReserva::vivas()->count(),
        ]);
    }

    /**
     * PUT /telas/consumo
     * Body: { producto_id, config_id?, metros } — metros vacío o 0 borra la fila.
     */
    public function guardar(Request $request)
    {
        $data = $request->validate([
            'producto_id' => 'required|integer|exists:productos,id',
            'config_id'   => 'nullable|integer|exists:producto_variante_configs,id',
            'metros'      => 'nullable|numeric|min:0|max:9999.99',
        ]);

        $configId = $data['config_id'] ?? null;
        if ($configId) {
            $cfg = ProductoVarianteConfig::find($configId);
            if (! $cfg || (int) $cfg->producto_id !== (int) $data['producto_id']) {
                return response()->json(['message' => 'Esa medida no es de este producto.'], 422);
            }
        }

        $metros = round((float) ($data['metros'] ?? 0), 2);

        $query = ProductoConsumoTela::where('producto_id', $data['producto_id'])
            ->when($configId, fn ($q) => $q->where('config_id', $configId), fn ($q) => $q->whereNull('config_id'));

        if ($metros <= 0) {
            $query->delete();
            return response()->json(['producto_id' => (int) $data['producto_id'], 'config_id' => $configId, 'metros' => null]);
        }

        $fila = $query->first();
        if ($fila) {
            $fila->update(['metros' => $metros]);
        } else {
            $fila = ProductoConsumoTela::create([
                'producto_id' => $data['producto_id'],
                'config_id'   => $configId,
                'metros'      => $metros,
            ]);
        }

        return response()->json(['producto_id' => (int) $fila->producto_id, 'config_id' => $fila->config_id, 'metros' => (float) $fila->metros]);
    }

    /**
     * PUT /telas/consumo/activo
     * Body: { activo: bool }. Apagarla suelta la tela que estuviera apartada.
     */
    public function activar(Request $request)
    {
        $data = $request->validate(['activo' => 'required|boolean']);

        $liberadas = ConsumoTelas::definir((bool) $data['activo']);

        return response()->json([
            'activo'    => ConsumoTelas::activo(),
            'liberadas' => $liberadas,
        ]);
    }

    private static function metrosDe(?ProductoConsumoTela $fila): ?float
    {
        return $fila ? (float) $fila->metros : null;
    }
}
