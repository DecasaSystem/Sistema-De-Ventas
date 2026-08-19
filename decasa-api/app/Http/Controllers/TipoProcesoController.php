<?php

namespace App\Http\Controllers;

use App\Models\PerfilProduccion;
use App\Models\ProduccionPaso;
use App\Models\TipoProceso;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Los procesos del taller, mantenidos por el supervisor.
 *
 * Leer puede cualquiera que trabaje en producción —las listas necesitan los
 * nombres y colores—, pero crear y cambiar es solo del supervisor.
 */
class TipoProcesoController extends Controller
{
    /** GET /api/tipos-proceso?incluir_inactivos=1 */
    public function index(Request $request)
    {
        $q = TipoProceso::query()->orderBy('orden')->orderBy('nombre');
        if (! $request->boolean('incluir_inactivos')) {
            $q->where('activo', true);
        }

        return response()->json([
            'tipos'    => $q->get(),
            // Antes era la constante fija TipoProceso::PERFILES; ahora sale
            // del catálogo que se mantiene desde Gestión, así que un perfil
            // nuevo aparece aquí solo, sin tocar código.
            'perfiles' => PerfilProduccion::where('activo', true)->orderBy('orden')->orderBy('nombre')->get(['clave', 'nombre']),
            'colores'  => TipoProceso::COLORES,
        ]);
    }

    /** POST /api/tipos-proceso */
    public function store(Request $request)
    {
        if ($request->user()->rol !== 'supervisor') {
            return response()->json(['message' => 'Solo un supervisor puede crear procesos.'], 403);
        }

        $data = $request->validate([
            'nombre'      => 'required|string|max:60',
            'descripcion' => 'nullable|string|max:160',
            'color'       => ['nullable', Rule::in(TipoProceso::COLORES)],
            'perfiles'    => 'required|array|min:1',
            'perfiles.*'  => Rule::in(PerfilProduccion::where('activo', true)->pluck('clave')),
        ]);

        // La clave sale del nombre y ya no cambia nunca: es lo que queda escrito
        // en cada paso. El nombre sí se puede corregir después.
        $clave = $this->claveLibre($data['nombre']);

        $tipo = TipoProceso::create([
            'clave'       => $clave,
            'nombre'      => $data['nombre'],
            'descripcion' => $data['descripcion'] ?? null,
            'color'       => $data['color'] ?? 'slate',
            'perfiles'    => $data['perfiles'],
            'orden'       => (int) (TipoProceso::max('orden') ?? 0) + 10,
            'activo'      => true,
        ]);
        TipoProceso::olvidarCache();

        return response()->json($tipo, 201);
    }

    /** PATCH /api/tipos-proceso/{id} */
    public function update(Request $request, int $id)
    {
        if ($request->user()->rol !== 'supervisor') {
            return response()->json(['message' => 'Solo un supervisor puede cambiar procesos.'], 403);
        }

        $tipo = TipoProceso::findOrFail($id);

        $data = $request->validate([
            'nombre'      => 'sometimes|required|string|max:60',
            'descripcion' => 'sometimes|nullable|string|max:160',
            'color'       => ['sometimes', Rule::in(TipoProceso::COLORES)],
            'perfiles'    => 'sometimes|array|min:1',
            'perfiles.*'  => Rule::in(PerfilProduccion::where('activo', true)->pluck('clave')),
            'orden'       => 'sometimes|integer|min:0|max:9999',
            'activo'      => 'sometimes|boolean',
        ]);

        // Apagar un proceso que hay gente trabajando ahora mismo dejaría ese
        // trabajo sin dónde marcarse.
        if (array_key_exists('activo', $data) && ! $data['activo']) {
            $enCurso = ProduccionPaso::where('tipo_proceso', $tipo->clave)
                ->whereIn('estado', ['pendiente', 'en_proceso'])->count();
            if ($enCurso > 0) {
                return response()->json([
                    'message' => "Hay {$enCurso} paso(s) de \"{$tipo->nombre}\" sin terminar. " .
                                 'Termínalos o cámbialos antes de desactivarlo.',
                ], 422);
            }
        }

        $tipo->update($data);
        TipoProceso::olvidarCache();

        return response()->json($tipo->fresh());
    }

    /**
     * DELETE /api/tipos-proceso/{id}
     *
     * Solo se borra de verdad si nunca se usó. Si ya hay trabajo hecho con él
     * se desactiva: borrarlo dejaría pasos apuntando a un proceso inexistente y
     * se perdería el registro de lo que se hizo.
     */
    public function destroy(Request $request, int $id)
    {
        if ($request->user()->rol !== 'supervisor') {
            return response()->json(['message' => 'Solo un supervisor puede borrar procesos.'], 403);
        }

        $tipo = TipoProceso::findOrFail($id);
        $usos = ProduccionPaso::where('tipo_proceso', $tipo->clave)->count();

        if ($usos > 0) {
            $enCurso = ProduccionPaso::where('tipo_proceso', $tipo->clave)
                ->whereIn('estado', ['pendiente', 'en_proceso'])->count();
            if ($enCurso > 0) {
                return response()->json([
                    'message' => "Hay {$enCurso} paso(s) de \"{$tipo->nombre}\" sin terminar. " .
                                 'Termínalos antes de quitarlo.',
                ], 422);
            }

            $tipo->update(['activo' => false]);
            TipoProceso::olvidarCache();

            return response()->json([
                'message' => "\"{$tipo->nombre}\" se usó en {$usos} paso(s), así que no se borra: " .
                             'queda desactivado y deja de ofrecerse, pero el trabajo hecho se conserva.',
                'desactivado' => true,
            ]);
        }

        $tipo->delete();
        TipoProceso::olvidarCache();

        return response()->json(['message' => 'Proceso eliminado.', 'desactivado' => false]);
    }

    /** Una clave que no choque con otra existente. */
    private function claveLibre(string $nombre): string
    {
        $base  = Str::slug($nombre, '_') ?: 'proceso';
        $base  = substr($base, 0, 36);
        $clave = $base;
        $n     = 2;
        while (TipoProceso::where('clave', $clave)->exists()) {
            $clave = substr($base, 0, 36 - strlen((string) $n) - 1) . '_' . $n;
            $n++;
        }
        return $clave;
    }
}
