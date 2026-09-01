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
                // Y para cuál de las dos líneas está cada uno. Va como lista de
                // pares —y no como mapa por id— porque un mapa con claves
                // numéricas viaja unas veces como objeto y otras como arreglo.
                // Aparte de `trabajador_ids` para que lo que ya lo lee siga
                // sirviendo.
                $data['lineas'] = $t->trabajadores
                    ->map(fn ($w) => [
                        'usuario_id' => $w->id,
                        'linea'      => $w->pivot->linea ?? TipoProceso::LINEA_AMBAS,
                    ])->values()->all();
                return $data;
            }),
            // ¿El taller lleva las restauraciones aparte de los muebles nuevos?
            // Apagado, la línea de cada quien no decide nada.
            'separa_restauraciones' => TipoProceso::separaRestauraciones(),
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
        ]);

        $trabajadores = $this->leerTrabajadores($request);
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
            'orden'          => 'sometimes|integer|min:0|max:9999',
            'activo'         => 'sometimes|boolean',
        ]);

        $trabajadores = $request->has('trabajadores') ? $this->leerTrabajadores($request) : null;

        if ($trabajadores !== null) {
            $this->exigirAlguien($trabajadores);
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

        if ($trabajadores !== null) {
            $tipo->trabajadores()->sync($trabajadores);
        }

        $tipo->update($data);
        TipoProceso::olvidarCache();

        return response()->json($tipo->fresh('trabajadores'));
    }

    /**
     * PATCH /api/tipos-proceso/ajustes
     *
     * Encender o apagar que las restauraciones se lleven aparte de lo nuevo.
     *
     * Encenderlo no mueve a nadie: todo el mundo arranca en "las dos", así que
     * el taller sigue igual hasta que el supervisor reparta proceso por
     * proceso. Y apagarlo devuelve las cosas a como estaban sin perder el
     * reparto, por si mañana se vuelve a encender.
     */
    public function ajustes(Request $request)
    {
        if ($request->user()->rol !== 'supervisor') {
            return response()->json(['message' => 'Solo un supervisor puede cambiar esto.'], 403);
        }

        $data = $request->validate([
            'separa_restauraciones' => 'required|boolean',
        ]);

        TipoProceso::definirSeparacion($data['separa_restauraciones']);

        // Al encender puede quedar algún proceso con una línea sin encargado
        // —no se bloquea, pero hay que decirlo: sus pasos quedarían en curso y
        // sin nadie que los vea.
        $sinCubrir = [];
        if ($data['separa_restauraciones']) {
            foreach (TipoProceso::where('activo', true)->with('trabajadores:id')->get() as $tipo) {
                foreach ($this->lineasSinEncargado($this->comoSync($tipo)) as $linea) {
                    $sinCubrir[] = ['proceso' => $tipo->nombre, 'linea' => $linea];
                }
            }
        }

        return response()->json([
            'separa_restauraciones' => $data['separa_restauraciones'],
            'sin_cubrir'            => $sinCubrir,
            'message'               => $data['separa_restauraciones']
                ? 'Las restauraciones se llevan aparte. Reparte los encargados en cada proceso.'
                : 'Las restauraciones vuelven a ir con todo lo demás.',
        ]);
    }

    /**
     * A quién se le asigna el proceso y en qué línea, tal como llega.
     *
     * Acepta las dos formas: la lista de ids de siempre —que deja a todos en
     * "las dos", igual que antes— y la nueva con línea por persona. Una app
     * que lleve abierta desde antes del cambio sigue guardando bien.
     *
     * @return array<int, array{linea:string}>  listo para sync()
     */
    private function leerTrabajadores(Request $request): array
    {
        $crudo = $request->input('trabajadores', []);
        $conLinea = is_array($crudo) && ! empty($crudo) && is_array(reset($crudo));

        if (! $conLinea) {
            $data = $request->validate([
                'trabajadores'   => 'nullable|array',
                'trabajadores.*' => ['integer', Rule::exists('usuarios', 'id')->where('apto_produccion', true)],
            ]);

            return array_fill_keys($data['trabajadores'] ?? [], ['linea' => TipoProceso::LINEA_AMBAS]);
        }

        $data = $request->validate([
            'trabajadores'         => 'array',
            'trabajadores.*.id'    => ['required', 'integer', Rule::exists('usuarios', 'id')->where('apto_produccion', true)],
            'trabajadores.*.linea' => ['nullable', Rule::in([TipoProceso::LINEA_AMBAS, ...TipoProceso::LINEAS])],
        ]);

        $sync = [];
        foreach ($data['trabajadores'] as $t) {
            $sync[(int) $t['id']] = ['linea' => $t['linea'] ?? TipoProceso::LINEA_AMBAS];
        }

        return $sync;
    }

    /**
     * Un proceso necesita al menos UNA persona que entre al programa.
     *
     * No basta con que la lista no esté vacía: la gente de fábrica no tiene
     * correo ni contraseña, así que nunca abre "Mis pasos". Un proceso donde
     * solo hay gente de fábrica deja sus pasos en curso pero invisibles para
     * todos, y las piezas se quedan paradas esperando a alguien que no puede
     * llegar. Pasó de verdad con Despacho.
     *
     * Con las restauraciones aparte hay que comprobarlo LÍNEA POR LÍNEA: un
     * proceso donde todos los encargados quedaron en "muebles nuevos" deja los
     * pasos de las restauraciones exactamente igual de huérfanos.
     *
     * @param array<int, array{linea:string}> $trabajadores
     */
    private function exigirAlguien(array $trabajadores): void
    {
        if (empty($trabajadores)) {
            throw ValidationException::withMessages([
                'trabajadores' => ['Elige al menos un trabajador que haga este proceso.'],
            ]);
        }

        $sinCubrir = $this->lineasSinEncargado($trabajadores);

        if (empty($sinCubrir)) {
            return;
        }

        if (! TipoProceso::separaRestauraciones()) {
            throw ValidationException::withMessages([
                'trabajadores' => [
                    'Falta alguien que pueda confirmar este paso. Los que marcaste no entran '
                    . 'al programa, así que nunca lo verán en "Mis pasos" y las piezas se '
                    . 'quedarían paradas. Agrega al menos un encargado con acceso.',
                ],
            ]);
        }

        $quePasa = array_map(
            fn ($l) => $l === TipoProceso::LINEA_RESTAURACION ? 'las restauraciones' : 'los muebles nuevos',
            $sinCubrir,
        );

        throw ValidationException::withMessages([
            'trabajadores' => [
                'Falta un encargado con acceso al programa para ' . implode(' y ', $quePasa) . '. '
                . 'Sin él esos pasos quedarían en curso y sin que nadie los vea. Marca a alguien '
                . 'en esa línea, o déjalo en "las dos".',
            ],
        ]);
    }

    /**
     * De qué líneas se quedaría el proceso sin nadie que confirme el paso.
     *
     * Con el interruptor apagado solo hay una bolsa, así que se comprueba una
     * vez: o hay alguien con acceso, o no.
     *
     * @param array<int, array{linea:string}> $trabajadores
     * @return array<int, string>
     */
    private function lineasSinEncargado(array $trabajadores): array
    {
        $conAcceso = Usuario::whereIn('id', array_keys($trabajadores))
            ->where('activo', true)->usaElPrograma()->pluck('id')->all();

        if (! TipoProceso::separaRestauraciones()) {
            return empty($conAcceso) ? [TipoProceso::LINEA_AMBAS] : [];
        }

        $sinCubrir = [];
        foreach (TipoProceso::LINEAS as $linea) {
            $hay = collect($conAcceso)->contains(
                fn ($id) => in_array($trabajadores[$id]['linea'] ?? TipoProceso::LINEA_AMBAS,
                                     [TipoProceso::LINEA_AMBAS, $linea], true)
            );
            if (! $hay) {
                $sinCubrir[] = $linea;
            }
        }

        return $sinCubrir;
    }

    /** Lo que ya está guardado, con la forma que espera lineasSinEncargado(). */
    private function comoSync(TipoProceso $tipo): array
    {
        return $tipo->trabajadores
            ->mapWithKeys(fn ($w) => [$w->id => ['linea' => $w->pivot->linea ?? TipoProceso::LINEA_AMBAS]])
            ->all();
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
