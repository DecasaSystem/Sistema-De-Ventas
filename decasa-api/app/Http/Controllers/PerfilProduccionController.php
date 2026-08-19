<?php

namespace App\Http\Controllers;

use App\Models\PerfilProduccion;
use App\Models\TipoProceso;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Los perfiles de producción (ebanista, tapicero, despachador, o el que el
 * negocio quiera crear), mantenidos por el supervisor desde Gestión.
 *
 * Leer puede cualquiera —el formulario de trabajador y el de tipos de
 * proceso necesitan la lista—, pero crear y cambiar es solo del supervisor.
 */
class PerfilProduccionController extends Controller
{
    /** GET /api/perfiles-produccion?incluir_inactivos=1 */
    public function index(Request $request)
    {
        $q = PerfilProduccion::query()->orderBy('orden')->orderBy('nombre');
        if (! $request->boolean('incluir_inactivos')) {
            $q->where('activo', true);
        }

        return response()->json($q->get());
    }

    /** POST /api/perfiles-produccion */
    public function store(Request $request)
    {
        if ($request->user()->rol !== 'supervisor') {
            return response()->json(['message' => 'Solo un supervisor puede crear perfiles.'], 403);
        }

        $data = $request->validate([
            'nombre' => 'required|string|max:60',
        ]);

        $clave = $this->claveLibre($data['nombre']);

        $perfil = PerfilProduccion::create([
            'clave'  => $clave,
            'nombre' => $data['nombre'],
            'orden'  => (int) (PerfilProduccion::max('orden') ?? 0) + 10,
            'activo' => true,
        ]);

        return response()->json($perfil, 201);
    }

    /** PATCH /api/perfiles-produccion/{id} */
    public function update(Request $request, int $id)
    {
        if ($request->user()->rol !== 'supervisor') {
            return response()->json(['message' => 'Solo un supervisor puede cambiar perfiles.'], 403);
        }

        $perfil = PerfilProduccion::findOrFail($id);

        $data = $request->validate([
            'nombre' => 'sometimes|required|string|max:60',
            'orden'  => 'sometimes|integer|min:0|max:9999',
            'activo' => 'sometimes|boolean',
        ]);

        $perfil->update($data);

        return response()->json($perfil->fresh());
    }

    /**
     * DELETE /api/perfiles-produccion/{id}
     *
     * Solo se borra de verdad si nadie lo tiene asignado. Si algún
     * trabajador lo tiene, se desactiva: borrarlo lo dejaría sin perfil de
     * un momento a otro, sin que nadie se lo haya quitado a propósito.
     */
    public function destroy(Request $request, int $id)
    {
        if ($request->user()->rol !== 'supervisor') {
            return response()->json(['message' => 'Solo un supervisor puede borrar perfiles.'], 403);
        }

        $perfil = PerfilProduccion::findOrFail($id);
        $usados = Usuario::where('perfil_produccion_id', $id)->count();

        if ($usados > 0) {
            $perfil->update(['activo' => false]);

            return response()->json([
                'message' => "\"{$perfil->nombre}\" lo tiene asignado {$usados} trabajador(es), así que no se borra: " .
                             'queda desactivado y deja de ofrecerse, pero conservan su asignación.',
                'desactivado' => true,
            ]);
        }

        $perfil->delete();

        return response()->json(['message' => 'Perfil eliminado.', 'desactivado' => false]);
    }

    /** Una clave que no choque con otra existente ni con las de TipoProceso ya en uso. */
    private function claveLibre(string $nombre): string
    {
        $base  = Str::slug($nombre, '_') ?: 'perfil';
        $base  = substr($base, 0, 36);
        $clave = $base;
        $n     = 2;
        while (PerfilProduccion::where('clave', $clave)->exists()) {
            $clave = substr($base, 0, 36 - strlen((string) $n) - 1) . '_' . $n;
            $n++;
        }
        return $clave;
    }
}
