<?php

namespace App\Http\Controllers;

use App\Models\ProduccionPaso;
use App\Models\TipoProceso;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

        $tipos = $q->with('trabajadores:id,nombre')->get();

        return response()->json([
            // trabajador_ids: para que el front pinte los seleccionados sin
            // tener que recorrer el objeto de la relación.
            'tipos'    => $tipos->map(function (TipoProceso $t) {
                $data = $t->toArray();
                $data['trabajador_ids'] = $t->trabajadores->pluck('id')->all();
                return $data;
            }),
            // Quiénes trabajan este proceso. Una sola lista que se lee de dos
            // maneras: los que entran al programa son además los encargados —ven
            // el paso y lo confirman—, y los de fábrica no lo ven pero salen de
            // primeros al anotar quién hizo el trabajo.
            'trabajadores' => Usuario::where('activo', true)->aptoProduccion()
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'rol', 'no_usa_programa']),
            'colores'  => TipoProceso::COLORES,
        ]);
    }

    /** POST /api/tipos-proceso */
    public function store(Request $request)
    {
        if ($request->user()->rol !== 'supervisor') {
            return response()->json(['message' => 'Solo un supervisor puede crear procesos.'], 403);
        }

        // Un proceso tiene que quedar en manos de alguien (ver exigirAlguien).
        $data = $request->validate([
            'nombre'         => 'required|string|max:60',
            'descripcion'    => 'nullable|string|max:160',
            'color'          => ['nullable', Rule::in(TipoProceso::COLORES)],
            'trabajadores'   => 'nullable|array',
            'trabajadores.*' => ['integer', Rule::exists('usuarios', 'id')->where('apto_produccion', true)],
        ]);

        $trabajadores = $data['trabajadores'] ?? [];
        $this->exigirAlguien($trabajadores);

        // La clave sale del nombre y ya no cambia nunca: es lo que queda escrito
        // en cada paso. El nombre sí se puede corregir después.
        $clave = $this->claveLibre($data['nombre']);

        $tipo = TipoProceso::create([
            'clave'       => $clave,
            'nombre'      => $data['nombre'],
            'descripcion' => $data['descripcion'] ?? null,
            'color'       => $data['color'] ?? 'slate',
            'orden'       => (int) (TipoProceso::max('orden') ?? 0) + 10,
            'activo'      => true,
        ]);
        $tipo->trabajadores()->sync($trabajadores);
        TipoProceso::olvidarCache();

        return response()->json($tipo->fresh('trabajadores'), 201);
    }

    /** PATCH /api/tipos-proceso/{id} */
    public function update(Request $request, int $id)
    {
        if ($request->user()->rol !== 'supervisor') {
            return response()->json(['message' => 'Solo un supervisor puede cambiar procesos.'], 403);
        }

        $tipo = TipoProceso::findOrFail($id);

        $data = $request->validate([
            'nombre'         => 'sometimes|required|string|max:60',
            'descripcion'    => 'sometimes|nullable|string|max:160',
            'color'          => ['sometimes', Rule::in(TipoProceso::COLORES)],
            'trabajadores'   => 'sometimes|array',
            'trabajadores.*' => ['integer', Rule::exists('usuarios', 'id')->where('apto_produccion', true)],
            'orden'          => 'sometimes|integer|min:0|max:9999',
            'activo'         => 'sometimes|boolean',
        ]);

        if (array_key_exists('trabajadores', $data)) {
            $this->exigirAlguien($data['trabajadores']);
        }

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

        if (array_key_exists('trabajadores', $data)) {
            $tipo->trabajadores()->sync($data['trabajadores']);
        }

        // 'trabajadores' vive en su propia tabla, no en una columna: si se
        // deja en $data, update() intentaría escribir una columna que no existe.
        unset($data['trabajadores']);

        $tipo->update($data);
        TipoProceso::olvidarCache();

        return response()->json($tipo->fresh('trabajadores'));
    }

    /**
     * Un proceso necesita al menos UNA persona que entre al programa.
     *
     * No basta con que la lista no esté vacía: la gente de fábrica no tiene
     * correo ni contraseña, así que nunca abre "Mis pasos". Un proceso donde
     * solo hay gente de fábrica deja sus pasos en curso pero invisibles para
     * todos, y las piezas se quedan paradas esperando a alguien que no puede
     * llegar. Pasó de verdad con Despacho.
     */
    private function exigirAlguien(array $trabajadores): void
    {
        if (empty($trabajadores)) {
            throw ValidationException::withMessages([
                'trabajadores' => ['Elige al menos un trabajador que haga este proceso.'],
            ]);
        }

        $puedenVerlo = Usuario::whereIn('id', $trabajadores)
            ->where('activo', true)->usaElPrograma()->count();

        if ($puedenVerlo === 0) {
            throw ValidationException::withMessages([
                'trabajadores' => [
                    'Falta alguien que pueda confirmar este paso. Los que marcaste no entran '
                    . 'al programa, así que nunca lo verán en "Mis pasos" y las piezas se '
                    . 'quedarían paradas. Agrega al menos un encargado con acceso.',
                ],
            ]);
        }
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
