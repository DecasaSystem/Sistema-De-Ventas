<?php

namespace App\Http\Controllers;

use App\Models\Tienda;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TiendaController extends Controller
{
    public function index()
    {
        return response()->json(Tienda::where('activa', true)->where('es_fabrica', false)->get());
    }

    /**
     * GET /api/tiendas/admin — para el módulo de Gestión. A diferencia de
     * index(), trae todas: activas e inactivas, incluida la fábrica.
     */
    public function adminIndex()
    {
        return response()->json(Tienda::orderBy('es_fabrica', 'desc')->orderBy('nombre')->get());
    }

    public function store(Request $request)
    {
        $data = $this->validarDatos($request);

        $tienda = DB::transaction(function () use ($data) {
            $this->asegurarUnicidad($data);

            return Tienda::create([...$data, 'activa' => true]);
        });

        return response()->json($tienda, 201);
    }

    public function update(Request $request, int $id)
    {
        $tienda = Tienda::findOrFail($id);
        $data   = $this->validarDatos($request, sometimes: true);

        DB::transaction(function () use ($tienda, $data) {
            $this->asegurarUnicidad($data, exceptoId: $tienda->id);
            $tienda->update($data);
        });

        return response()->json($tienda->fresh());
    }

    /**
     * No es un DELETE físico si algo depende de la tienda: se desactiva
     * (mismo criterio que ya se usa para filtrar tiendas en el resto de la
     * app). Solo se borra de verdad si está completamente limpia.
     */
    public function destroy(int $id)
    {
        $tienda = Tienda::findOrFail($id);

        if ($tienda->es_fabrica) {
            return response()->json([
                'message' => 'No se puede eliminar la tienda marcada como fábrica. Asigna otra fábrica primero.',
            ], 422);
        }
        if ($tienda->es_independientes) {
            return response()->json([
                'message' => 'No se puede eliminar la sede de vendedores independientes.',
            ], 422);
        }

        $dependencias = [];
        if ($tienda->ordenes()->exists())    $dependencias[] = 'órdenes';
        if ($tienda->usuarios()->exists())   $dependencias[] = 'trabajadores';
        if ($tienda->inventarios()->where('cantidad_disponible', '>', 0)->exists()) $dependencias[] = 'inventario';

        if ($dependencias) {
            $tienda->update(['activa' => false]);
            return response()->json([
                'message'    => 'Tiene ' . implode(', ', $dependencias) . ' asociados, así que se desactivó en vez de eliminarla.',
                'desactivada' => true,
            ]);
        }

        $tienda->delete();

        return response()->json(['ok' => true]);
    }

    private function validarDatos(Request $request, bool $sometimes = false): array
    {
        $regla = fn(string $r) => $sometimes ? "sometimes|$r" : $r;

        return $request->validate([
            'nombre'            => [$regla('required'), 'string', 'max:100'],
            'ciudad'            => 'nullable|string|max:80',
            'direccion'         => 'nullable|string|max:200',
            'telefono'          => 'nullable|string|max:20',
            'es_fabrica'        => 'boolean',
            'es_independientes' => 'boolean',
            'activa'            => 'boolean',
        ], [
            'nombre.required' => 'El nombre es obligatorio.',
        ]);
    }

    /**
     * Solo puede haber una fábrica y una sede de independientes a la vez:
     * ~50 sitios del backend asumen exactamente una de cada una con
     * firstOrFail()/->value('id'), sin ningún candado real hasta ahora.
     */
    private function asegurarUnicidad(array $data, ?int $exceptoId = null): void
    {
        if (! empty($data['es_fabrica'])) {
            Tienda::where('es_fabrica', true)
                ->when($exceptoId, fn($q) => $q->where('id', '!=', $exceptoId))
                ->update(['es_fabrica' => false]);
        }
        if (! empty($data['es_independientes'])) {
            Tienda::where('es_independientes', true)
                ->when($exceptoId, fn($q) => $q->where('id', '!=', $exceptoId))
                ->update(['es_independientes' => false]);
        }
    }
}
