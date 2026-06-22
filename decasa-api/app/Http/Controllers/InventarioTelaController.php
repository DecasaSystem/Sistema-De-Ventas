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

        // Fuente primaria: catalogo_telas (ahora con metros_disponibles / metros_reservados)
        $catQuery = DB::table('catalogo_telas')
            ->select('id', 'marca', 'tipo', 'color', 'metros_disponibles', 'metros_reservados');

        if ($search) {
            $term = '%' . mb_strtolower($search) . '%';
            $catQuery->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(tipo) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(color) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(marca) LIKE ?', [$term]);
            });
        }
        if ($proveedor) {
            $catQuery->where('marca', $proveedor);
        }

        $desdeCatalogo = $catQuery->orderBy('marca')->orderBy('tipo')->orderBy('color')->get()
            ->map(fn ($t) => $this->formatCatalogo($t));

        // Fuente secundaria: inventario_telas (referencias del Excel sin equivalente en catálogo)
        $invQuery = InventarioTela::where('activo', true);

        if ($search) {
            $term = '%' . mb_strtolower($search) . '%';
            $invQuery->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(referencia) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(color) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(textura) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(proveedor) LIKE ?', [$term]);
            });
        }
        if ($proveedor) {
            $invQuery->where('proveedor', $proveedor);
        }

        $desdeInventario = $invQuery->orderBy('referencia')->get()
            ->map(fn ($t) => $this->formatInventario($t));

        $todas = $desdeCatalogo->concat($desdeInventario)->values();

        return response()->json($todas);
    }

    public function proveedores()
    {
        $desdeCatalogo   = DB::table('catalogo_telas')->distinct()->pluck('marca');
        $desdeInventario = InventarioTela::where('activo', true)
            ->whereNotNull('proveedor')
            ->distinct()
            ->pluck('proveedor');

        $todos = $desdeCatalogo->concat($desdeInventario)->unique()->sort()->values();

        return response()->json($todos);
    }

    public function validar(Request $request)
    {
        $marca = $request->query('marca');
        $tipo  = $request->query('tipo');
        $color = $request->query('color');

        // Lookup por marca+tipo+color en catalogo_telas
        if ($marca && $tipo && $color) {
            $cat = DB::table('catalogo_telas')
                ->where('marca', $marca)
                ->where('tipo', $tipo)
                ->where('color', $color)
                ->first();

            if (! $cat) {
                return response()->json(['disponible' => false, 'metros' => 0, 'mensaje' => 'Tela no encontrada en catálogo.']);
            }

            $libres = round((float) $cat->metros_disponibles - (float) $cat->metros_reservados, 2);

            return response()->json([
                'disponible' => $libres > 0,
                'metros'     => $libres,
                'referencia' => "{$cat->marca} · {$cat->tipo} · {$cat->color}",
            ]);
        }

        // Fallback: inventario_telas por referencia directa
        $referencia = $request->query('referencia');
        if ($referencia) {
            $tela = InventarioTela::where('referencia', $referencia)->where('activo', true)->first();
            if (! $tela) {
                return response()->json(['disponible' => false, 'metros' => 0, 'mensaje' => 'Tela no encontrada.']);
            }
            $libres = round((float) $tela->metros_disponibles - (float) $tela->metros_reservados, 2);

            return response()->json([
                'disponible' => $libres > 0,
                'metros'     => $libres,
                'referencia' => $tela->referencia,
            ]);
        }

        return response()->json(['disponible' => false, 'metros' => 0]);
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
            'fuente' => 'required|in:catalogo,inventario',
            'id'     => 'required|integer|min:1',
            'metros' => 'required|numeric|min:0.1',
            'nota'   => 'nullable|string|max:255',
        ]);

        if ($data['fuente'] === 'catalogo') {
            $cat = DB::table('catalogo_telas')->where('id', $data['id'])->first();
            if (! $cat) {
                return response()->json(['message' => 'Tela no encontrada en catálogo.'], 404);
            }

            DB::table('catalogo_telas')
                ->where('id', $data['id'])
                ->increment('metros_disponibles', $data['metros']);

            $cat    = DB::table('catalogo_telas')->where('id', $data['id'])->first();
            $nombre = "{$cat->marca} · {$cat->tipo} · {$cat->color}";

            $costureros = Usuario::where('rol', 'costurero')->where('activo', true)->pluck('id');
            foreach ($costureros as $uid) {
                NotificacionService::crear(
                    'tela_recargada',
                    'Tela recargada',
                    "Se agregaron {$data['metros']} m de {$nombre}." . ($data['nota'] ? " Nota: {$data['nota']}" : ''),
                    ['catalogo_id' => $cat->id, 'nombre' => $nombre],
                    $uid
                );
            }

            return response()->json($this->formatCatalogo($cat));
        }

        // fuente = inventario
        $tela = InventarioTela::where('id', $data['id'])->where('activo', true)->firstOrFail();
        $tela->increment('metros_disponibles', $data['metros']);

        $costureros = Usuario::where('rol', 'costurero')->where('activo', true)->pluck('id');
        foreach ($costureros as $uid) {
            NotificacionService::crear(
                'tela_recargada',
                'Tela recargada',
                "Se agregaron {$data['metros']} m de {$tela->referencia}." . ($data['nota'] ? " Nota: {$data['nota']}" : ''),
                ['tela_id' => $tela->id, 'referencia' => $tela->referencia],
                $uid
            );
        }

        return response()->json($this->formatInventario($tela->fresh()));
    }

    public function descontar(Request $request)
    {
        $data = $request->validate([
            'fuente' => 'required|in:catalogo,inventario',
            'id'     => 'required|integer|min:1',
            'metros' => 'required|numeric|min:0.01',
            'nota'   => 'nullable|string|max:255',
        ]);

        if ($data['fuente'] === 'catalogo') {
            $cat = DB::table('catalogo_telas')->where('id', $data['id'])->first();
            if (! $cat) {
                return response()->json(['message' => 'Tela no encontrada en catálogo.'], 404);
            }

            $libres = round((float) $cat->metros_disponibles - (float) $cat->metros_reservados, 2);
            if ($data['metros'] > $libres) {
                return response()->json(['message' => "Solo hay {$libres} m disponibles de esta tela."], 422);
            }

            DB::table('catalogo_telas')
                ->where('id', $data['id'])
                ->decrement('metros_disponibles', $data['metros']);

            $cat = DB::table('catalogo_telas')->where('id', $data['id'])->first();

            return response()->json($this->formatCatalogo($cat));
        }

        // fuente = inventario
        $tela = InventarioTela::where('id', $data['id'])->where('activo', true)->firstOrFail();
        $libres = round((float) $tela->metros_disponibles - (float) $tela->metros_reservados, 2);
        if ($data['metros'] > $libres) {
            return response()->json(['message' => "Solo hay {$libres} m disponibles de esta tela."], 422);
        }

        $tela->decrement('metros_disponibles', $data['metros']);

        return response()->json($this->formatInventario($tela->fresh()));
    }

    private function formatCatalogo(object $t): array
    {
        return [
            'id'                 => $t->id,
            'fuente'             => 'catalogo',
            'referencia'         => "{$t->marca} · {$t->tipo} · {$t->color}",
            'marca'              => $t->marca,
            'tipo'               => $t->tipo,
            'color'              => $t->color,
            'textura'            => '',
            'proveedor'          => $t->marca,
            'metros_disponibles' => (float) $t->metros_disponibles,
            'metros_reservados'  => (float) $t->metros_reservados,
            'metros_libres'      => round((float) $t->metros_disponibles - (float) $t->metros_reservados, 2),
        ];
    }

    private function formatInventario(InventarioTela $t): array
    {
        return [
            'id'                 => $t->id,
            'fuente'             => 'inventario',
            'referencia'         => $t->referencia,
            'marca'              => null,
            'tipo'               => null,
            'color'              => $t->color,
            'textura'            => $t->textura,
            'proveedor'          => $t->proveedor,
            'metros_disponibles' => (float) $t->metros_disponibles,
            'metros_reservados'  => (float) $t->metros_reservados,
            'metros_libres'      => round((float) $t->metros_disponibles - (float) $t->metros_reservados, 2),
        ];
    }
}
