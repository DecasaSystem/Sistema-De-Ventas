<?php

namespace App\Http\Controllers;

use App\Models\InventarioTela;
use App\Models\Usuario;
use App\Services\NotificacionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventarioTelaController extends Controller
{
    public function index(Request $request)
    {
        $search    = $request->query('search');
        $proveedor = $request->query('proveedor');

        // Telas que ya tienen entrada en inventario
        $query = InventarioTela::where('activo', true);
        if ($search) {
            $term = '%' . mb_strtolower($search) . '%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(referencia) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(color) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(textura) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(proveedor) LIKE ?', [$term]);
            });
        }
        if ($proveedor) {
            $query->where('proveedor', $proveedor);
        }

        $inventario = $query->orderBy('referencia')->get()
            ->map(fn ($t) => $this->format($t));

        // Telas del catálogo que aún no tienen fila en inventario_telas
        $refsExistentes = InventarioTela::where('activo', true)->pluck('referencia')->toArray();

        $catQuery = DB::table('catalogo_telas')
            ->select(DB::raw('tipo AS referencia, marca AS proveedor, MIN(color) AS color'))
            ->whereNotIn('tipo', $refsExistentes)
            ->groupBy('tipo', 'marca');

        if ($search) {
            $term = '%' . mb_strtolower($search) . '%';
            $catQuery->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(tipo) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(marca) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(color) LIKE ?', [$term]);
            });
        }
        if ($proveedor) {
            $catQuery->where('marca', $proveedor);
        }

        $desdeCatalogo = $catQuery->orderBy('tipo')->get()
            ->map(fn ($t) => [
                'id'                 => null,
                'referencia'         => $t->referencia,
                'color'              => $t->color ?? '',
                'textura'            => '',
                'proveedor'          => $t->proveedor,
                'metros_disponibles' => 0,
                'metros_reservados'  => 0,
                'metros_libres'      => 0,
                'solo_catalogo'      => true,
            ]);

        $todas = collect($inventario)->concat($desdeCatalogo)
            ->sortBy('referencia')
            ->values();

        return response()->json($todas);
    }

    public function proveedores()
    {
        $desdeInventario = InventarioTela::where('activo', true)
            ->whereNotNull('proveedor')
            ->distinct()
            ->pluck('proveedor');

        $desdeCatalogo = DB::table('catalogo_telas')
            ->distinct()
            ->pluck('marca');

        $todos = $desdeInventario->concat($desdeCatalogo)->unique()->sort()->values();

        return response()->json($todos);
    }

    public function validar(Request $request)
    {
        $referencia = $request->query('referencia');
        if (! $referencia) {
            return response()->json(['disponible' => false, 'metros' => 0]);
        }

        $tela = InventarioTela::where('referencia', $referencia)->where('activo', true)->first();

        if (! $tela) {
            return response()->json(['disponible' => false, 'metros' => 0, 'mensaje' => 'Tela no encontrada en inventario.']);
        }

        $libres = (float) $tela->metros_disponibles - (float) $tela->metros_reservados;

        return response()->json([
            'disponible' => $libres > 0,
            'metros'     => $libres,
            'referencia' => $tela->referencia,
        ]);
    }

    public function recargar(Request $request)
    {
        $usuario = $request->user();
        $puedeRecargar = ($usuario->recarga_telas && in_array($usuario->rol, ['vendedor', 'supervisor']))
            || $usuario->rol === 'supervisor';

        if (! $puedeRecargar) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $data = $request->validate([
            'referencia' => 'required|string',
            'metros'     => 'required|numeric|min:0.1',
            'nota'       => 'nullable|string|max:255',
        ]);

        // Buscar en inventario; si no existe, crearlo desde el catálogo
        $tela = InventarioTela::where('referencia', $data['referencia'])->where('activo', true)->first();

        if (! $tela) {
            $cat = DB::table('catalogo_telas')->where('tipo', $data['referencia'])->first();
            if (! $cat) {
                return response()->json(['message' => 'Tela no encontrada en inventario ni en catálogo.'], 404);
            }
            $tela = InventarioTela::create([
                'referencia'         => $data['referencia'],
                'color'              => $cat->color ?? '',
                'textura'            => '',
                'proveedor'          => $cat->marca ?? null,
                'metros_disponibles' => 0,
                'metros_reservados'  => 0,
                'activo'             => true,
            ]);
        }

        $tela->increment('metros_disponibles', $data['metros']);

        $costureros = Usuario::where('rol', 'costurero')->where('activo', true)->pluck('id');
        foreach ($costureros as $id) {
            NotificacionService::crear(
                'tela_recargada',
                'Tela recargada',
                "Se agregaron {$data['metros']} m de {$tela->referencia}." . ($data['nota'] ? " Nota: {$data['nota']}" : ''),
                ['tela_id' => $tela->id, 'referencia' => $tela->referencia],
                $id
            );
        }

        return response()->json($this->format($tela->fresh()));
    }

    public function descontar(Request $request)
    {
        $data = $request->validate([
            'referencia' => 'required|string',
            'metros'     => 'required|numeric|min:0.01',
            'nota'       => 'nullable|string|max:255',
        ]);

        $tela = InventarioTela::where('referencia', $data['referencia'])->where('activo', true)->firstOrFail();

        $libres = (float) $tela->metros_disponibles - (float) $tela->metros_reservados;
        if ($data['metros'] > $libres) {
            return response()->json(['message' => "Solo hay {$libres} m disponibles de esta tela."], 422);
        }

        $tela->decrement('metros_disponibles', $data['metros']);

        return response()->json($this->format($tela->fresh()));
    }

    private function format(InventarioTela $t): array
    {
        return [
            'id'                 => $t->id,
            'referencia'         => $t->referencia,
            'color'              => $t->color,
            'textura'            => $t->textura,
            'proveedor'          => $t->proveedor,
            'metros_disponibles' => (float) $t->metros_disponibles,
            'metros_reservados'  => (float) $t->metros_reservados,
            'metros_libres'      => round((float) $t->metros_disponibles - (float) $t->metros_reservados, 2),
        ];
    }
}
