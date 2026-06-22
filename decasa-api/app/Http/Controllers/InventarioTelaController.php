<?php

namespace App\Http\Controllers;

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

        $query = DB::table('catalogo_telas')->where('activo', true);

        if ($search) {
            $term = '%' . mb_strtolower($search) . '%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(tipo) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(color) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(marca) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(COALESCE(referencia,"")) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(COALESCE(textura,"")) LIKE ?', [$term]);
            });
        }
        if ($proveedor) {
            $query->where('marca', $proveedor);
        }

        $telas = $query->orderBy('marca')->orderBy('tipo')->orderBy('color')
            ->get(['id', 'marca', 'tipo', 'color', 'referencia', 'textura', 'metros_disponibles', 'metros_reservados'])
            ->map(fn ($t) => $this->format($t));

        return response()->json($telas);
    }

    public function proveedores()
    {
        $marcas = DB::table('catalogo_telas')
            ->where('activo', true)
            ->distinct()
            ->orderBy('marca')
            ->pluck('marca');

        return response()->json($marcas);
    }

    public function validar(Request $request)
    {
        $marca = $request->query('marca');
        $tipo  = $request->query('tipo');
        $color = $request->query('color');

        if (!$marca || !$tipo || !$color) {
            return response()->json(['disponible' => false, 'metros' => 0]);
        }

        $cat = DB::table('catalogo_telas')
            ->where('marca', $marca)
            ->where('tipo', $tipo)
            ->where('color', $color)
            ->where('activo', true)
            ->first();

        if (!$cat) {
            return response()->json(['disponible' => false, 'metros' => 0, 'mensaje' => 'Tela no encontrada en catálogo.']);
        }

        $libres = round((float) $cat->metros_disponibles - (float) $cat->metros_reservados, 2);

        return response()->json([
            'disponible' => $libres > 0,
            'metros'     => $libres,
            'referencia' => "{$cat->marca} · {$cat->tipo} · {$cat->color}",
        ]);
    }

    public function recargar(Request $request)
    {
        $usuario = $request->user();
        $puedeRecargar = ($usuario->recarga_telas && in_array($usuario->rol, ['vendedor', 'supervisor']))
            || $usuario->rol === 'supervisor';

        if (!$puedeRecargar) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $data = $request->validate([
            'id'     => 'required|integer|min:1',
            'metros' => 'required|numeric|min:0.1',
            'nota'   => 'nullable|string|max:255',
        ]);

        $cat = DB::table('catalogo_telas')->where('id', $data['id'])->where('activo', true)->first();
        if (!$cat) {
            return response()->json(['message' => 'Tela no encontrada.'], 404);
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

        return response()->json($this->format($cat));
    }

    public function descontar(Request $request)
    {
        $data = $request->validate([
            'id'     => 'required|integer|min:1',
            'metros' => 'required|numeric|min:0.01',
            'nota'   => 'nullable|string|max:255',
        ]);

        $cat = DB::table('catalogo_telas')->where('id', $data['id'])->where('activo', true)->first();
        if (!$cat) {
            return response()->json(['message' => 'Tela no encontrada.'], 404);
        }

        $libres = round((float) $cat->metros_disponibles - (float) $cat->metros_reservados, 2);
        if ($data['metros'] > $libres) {
            return response()->json(['message' => "Solo hay {$libres} m disponibles de esta tela."], 422);
        }

        DB::table('catalogo_telas')
            ->where('id', $data['id'])
            ->decrement('metros_disponibles', $data['metros']);

        $cat = DB::table('catalogo_telas')->where('id', $data['id'])->first();

        return response()->json($this->format($cat));
    }

    private function format(object $t): array
    {
        return [
            'id'                 => $t->id,
            'marca'              => $t->marca,
            'tipo'               => $t->tipo,
            'color'              => $t->color,
            'referencia'         => $t->referencia ?? null,
            'textura'            => $t->textura ?? null,
            'metros_disponibles' => (float) $t->metros_disponibles,
            'metros_reservados'  => (float) $t->metros_reservados,
            'metros_libres'      => round((float) $t->metros_disponibles - (float) $t->metros_reservados, 2),
        ];
    }
}
