<?php

namespace App\Http\Controllers;

use App\Models\Modulo;
use App\Models\ModuloItem;
use App\Models\Usuario;
use App\Services\NotificacionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Los ítems de un módulo creado a partir de Telas: las espumas, los hilos,
 * las láminas de cada empresa.
 *
 * Es la misma pantalla que Telas y hace lo mismo —listar, filtrar por marca,
 * agregar, ponerle foto, recargar, descontar— sobre `modulo_items`, que es
 * de cada módulo. Los permisos son los de Telas: quien recarga tela recarga
 * espuma, quien descuenta tela descuenta espuma. No hay un permiso por cada
 * módulo creado porque el que administra tendría que repartir uno nuevo cada
 * vez que crea uno.
 */
class ModuloItemController extends Controller
{
    /** GET /api/modulos/{clave}/items */
    public function index(Request $request, string $clave)
    {
        $modulo = $this->modulo($clave);

        $query = ModuloItem::where('modulo_id', $modulo->id)->where('activo', true);

        if ($search = $request->query('search')) {
            $term = '%' . mb_strtolower($search) . '%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(tipo) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(color) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(marca) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(COALESCE(referencia,"")) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(COALESCE(textura,"")) LIKE ?', [$term]);
            });
        }
        if ($proveedor = $request->query('proveedor')) {
            $query->where('marca', $proveedor);
        }

        $items = $query->orderBy('marca')->orderBy('tipo')->orderBy('color')->get()
            ->map(fn (ModuloItem $i) => $i->paraPantalla());

        return response()->json($items);
    }

    /** GET /api/modulos/{clave}/items/proveedores */
    public function proveedores(string $clave)
    {
        $modulo = $this->modulo($clave);

        $marcas = ModuloItem::where('modulo_id', $modulo->id)
            ->where('activo', true)
            ->distinct()
            ->orderBy('marca')
            ->pluck('marca');

        return response()->json($marcas);
    }

    /**
     * POST /api/modulos/{clave}/items
     *
     * Igual que agregar una tela: si ya existía esa marca+tipo+color se
     * revive (o se le suma la cantidad) en vez de fallar por duplicado.
     */
    public function store(Request $request, string $clave)
    {
        $modulo = $this->modulo($clave);
        if (! $this->puedeRecargar($request->user())) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $data = $request->validate([
            'marca'            => 'required|string|max:100',
            'tipo'             => 'required|string|max:100',
            'color'            => 'required|string|max:100',
            'referencia'       => 'nullable|string|max:200',
            'textura'          => 'nullable|string|max:100',
            'foto_url'         => 'nullable|string|max:500',
            'cantidad_inicial' => 'nullable|numeric|min:0',
        ]);

        $item = ModuloItem::firstOrCreate(
            [
                'modulo_id' => $modulo->id,
                'marca'     => trim($data['marca']),
                'tipo'      => trim($data['tipo']),
                'color'     => trim($data['color']),
            ],
            [
                'activo'     => true,
                'referencia' => isset($data['referencia']) ? trim($data['referencia']) : null,
                'textura'    => isset($data['textura'])    ? trim($data['textura'])    : null,
                'foto_url'   => $data['foto_url'] ?? null,
            ]
        );

        $cambios = [];
        if (! $item->activo) {
            $cambios['activo'] = true;
        }
        if (! empty($data['foto_url']) && $item->foto_url !== $data['foto_url']) {
            $cambios['foto_url'] = $data['foto_url'];
        }
        if ($cambios) {
            $item->update($cambios);
        }

        $cantidad = round((float) ($data['cantidad_inicial'] ?? 0), 2);
        if ($cantidad > 0) {
            ModuloItem::where('id', $item->id)->increment('cantidad_disponible', $cantidad);
            $item->refresh();
        }

        return response()->json($item->paraPantalla(), 201);
    }

    /** PATCH /api/modulos/{clave}/items/{id} — la foto, la referencia o la textura. */
    public function update(Request $request, string $clave, int $id)
    {
        $modulo = $this->modulo($clave);
        if (! $this->puedeRecargar($request->user())) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $data = $request->validate([
            'foto_url'   => 'sometimes|nullable|string|max:500',
            'referencia' => 'sometimes|nullable|string|max:200',
            'textura'    => 'sometimes|nullable|string|max:100',
        ]);

        $item = ModuloItem::where('modulo_id', $modulo->id)->findOrFail($id);
        $item->update($data);

        return response()->json($item->paraPantalla());
    }

    /** POST /api/modulos/{clave}/items/recargar */
    public function recargar(Request $request, string $clave)
    {
        $modulo = $this->modulo($clave);
        if (! $this->puedeRecargar($request->user())) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $data = $request->validate([
            'id'       => 'required|integer|min:1',
            'cantidad' => 'required|numeric|min:0.01',
            'nota'     => 'nullable|string|max:255',
        ]);

        $item = ModuloItem::where('modulo_id', $modulo->id)->where('activo', true)->find($data['id']);
        if (! $item) {
            return response()->json(['message' => 'No se encontró en el inventario.'], 404);
        }

        $cantidad = round((float) $data['cantidad'], 2);
        ModuloItem::where('id', $item->id)->increment('cantidad_disponible', $cantidad);
        $item->refresh();

        // Le llega a quien descuenta, igual que con las telas: es quien
        // necesita saber que ya hay de dónde sacar.
        $unidad = $modulo->configCompleta()['unidad'] ?? '';
        $nombre = "{$item->marca} · {$item->tipo} · {$item->color}";
        $aviso  = "Se agregaron {$cantidad} {$unidad} de {$nombre}." . (! empty($data['nota']) ? " Nota: {$data['nota']}" : '');
        foreach (Usuario::where('acceso_telas', true)->where('activo', true)->pluck('id') as $uid) {
            NotificacionService::crear(
                'tela_recargada',
                "{$modulo->nombre}: recarga",
                $aviso,
                ['modulo' => $modulo->clave, 'item_id' => $item->id, 'nombre' => $nombre],
                $uid
            );
        }

        return response()->json($item->paraPantalla());
    }

    /** POST /api/modulos/{clave}/items/descontar */
    public function descontar(Request $request, string $clave)
    {
        $modulo = $this->modulo($clave);
        if (! $this->puedeDescontar($request->user())) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $data = $request->validate([
            'id'       => 'required|integer|min:1',
            'cantidad' => 'required|numeric|min:0.01',
            'nota'     => 'nullable|string|max:255',
        ]);

        $cantidad = round((float) $data['cantidad'], 2);
        $unidad   = $modulo->configCompleta()['unidad'] ?? '';

        return DB::transaction(function () use ($modulo, $data, $cantidad, $unidad) {
            $item = ModuloItem::where('modulo_id', $modulo->id)->where('activo', true)
                ->lockForUpdate()->find($data['id']);
            if (! $item) {
                return response()->json(['message' => 'No se encontró en el inventario.'], 404);
            }

            $libre = round((float) $item->cantidad_disponible, 2);
            if ($cantidad > $libre + 0.005) {
                return response()->json(['message' => "Solo hay {$libre} {$unidad} disponibles."], 422);
            }

            ModuloItem::where('id', $item->id)->decrement('cantidad_disponible', $cantidad);
            $item->refresh();

            return response()->json($item->paraPantalla());
        });
    }

    /** El módulo de esa clave, si es de los que nacieron de Telas. */
    private function modulo(string $clave): Modulo
    {
        return Modulo::where('clave', $clave)->where('plantilla', 'telas')->firstOrFail();
    }

    private function puedeRecargar(Usuario $usuario): bool
    {
        return $usuario->rol === 'supervisor' || (bool) $usuario->recarga_telas;
    }

    private function puedeDescontar(Usuario $usuario): bool
    {
        return $usuario->rol === 'supervisor' || (bool) $usuario->acceso_telas;
    }
}
