<?php

namespace App\Http\Controllers;

use App\Models\NominaConceptoEmpleador;
use App\Models\NominaConceptoTarifa;
use App\Models\NominaConceptoTrabajador;
use App\Services\CicloNomina;
use App\Services\CostoEmpleador;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Lo que la empresa paga por detrás de la nómina, configurable: los
 * conceptos (pensión, ARL, prima…), su porcentaje con vigencia y si aplican
 * a todos por defecto. Las excepciones de cada persona van en su ficha
 * (EmpleadoController). Acceso ya validado por `permiso:acceso_nomina`.
 *
 * Nada se borra: un concepto que la ley quita se desactiva (los pagos viejos
 * lo nombran), y un cambio de porcentaje se agrega con su fecha en vez de
 * pisar el anterior. Lo ya pagado no se mueve nunca: cada pago congeló su costo.
 */
class NominaConceptoEmpleadorController extends Controller
{
    /** GET /api/nomina/conceptos-empleador?incluir_inactivos=1 */
    public function index(Request $request)
    {
        $hoy = CicloNomina::hoy();

        $q = NominaConceptoEmpleador::with(['tarifas', 'baseConcepto:id,clave,nombre'])
            ->orderBy('orden')->orderBy('id');
        if (! $request->boolean('incluir_inactivos')) {
            $q->where('activo', true);
        }

        $excepciones = NominaConceptoTrabajador::selectRaw('concepto_id, COUNT(*) AS n')
            ->groupBy('concepto_id')->pluck('n', 'concepto_id');

        return response()->json([
            'conceptos' => $q->get()->map(fn (NominaConceptoEmpleador $c) => $this->comoJson($c, $hoy, (int) ($excepciones[$c->id] ?? 0))),
            'bono_es_salario' => CostoEmpleador::bonoEsSalario(),
        ]);
    }

    /** POST /api/nomina/conceptos-empleador — un concepto nuevo (la ley trae otro aporte). */
    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'             => 'required|string|max:80',
            'grupo'              => ['required', Rule::in(NominaConceptoEmpleador::GRUPOS)],
            'base'               => ['required', Rule::in(NominaConceptoEmpleador::BASES)],
            'base_concepto_id'   => 'nullable|required_if:base,concepto|exists:nomina_conceptos_empleador,id',
            'porcentaje'         => 'required|numeric|min:0|max:100',
            'desde'              => 'nullable|date',
            'aplica_por_defecto' => 'sometimes|boolean',
            'nota'               => 'nullable|string|max:200',
        ], [
            'nombre.required'           => 'Ponle un nombre al concepto.',
            'porcentaje.required'       => 'Falta el porcentaje.',
            'base_concepto_id.required_if' => 'Elige sobre qué concepto se calcula.',
        ]);

        $concepto = NominaConceptoEmpleador::create([
            'clave'              => $this->claveLibre($data['nombre']),
            'nombre'             => $data['nombre'],
            'grupo'              => $data['grupo'],
            'base'               => $data['base'],
            'base_concepto_id'   => $data['base'] === 'concepto' ? $data['base_concepto_id'] : null,
            'aplica_por_defecto' => $data['aplica_por_defecto'] ?? true,
            'activo'             => true,
            'orden'              => (int) NominaConceptoEmpleador::max('orden') + 1,
            'nota'               => $data['nota'] ?? null,
        ]);

        NominaConceptoTarifa::create([
            'concepto_id' => $concepto->id,
            'porcentaje'  => $data['porcentaje'],
            'desde'       => $data['desde'] ?? CicloNomina::hoy()->toDateString(),
            'creado_por'  => $request->user()->id,
        ]);

        CostoEmpleador::olvidarCache();

        return response()->json($this->comoJson($concepto->fresh(['tarifas', 'baseConcepto']), CicloNomina::hoy(), 0), 201);
    }

    /**
     * PATCH /api/nomina/conceptos-empleador/{id}
     *
     * Nombre, si aplica a todos por defecto, si está activo, la nota. El
     * porcentaje NO se cambia aquí: va con su fecha (agregarTarifa).
     */
    public function update(Request $request, int $id)
    {
        $concepto = NominaConceptoEmpleador::findOrFail($id);

        $data = $request->validate([
            'nombre'             => 'sometimes|required|string|max:80',
            'aplica_por_defecto' => 'sometimes|boolean',
            'activo'             => 'sometimes|boolean',
            'orden'              => 'sometimes|integer|min:0',
            'nota'               => 'sometimes|nullable|string|max:200',
        ]);

        // Desactivar uno del que otro depende dejaría los intereses sin base.
        if (($data['activo'] ?? true) === false) {
            $dependiente = NominaConceptoEmpleador::where('base_concepto_id', $concepto->id)->where('activo', true)->first();
            if ($dependiente) {
                throw ValidationException::withMessages([
                    'activo' => ["\"{$dependiente->nombre}\" se calcula sobre \"{$concepto->nombre}\": desactívalo primero."],
                ]);
            }
        }

        $concepto->update($data);
        CostoEmpleador::olvidarCache();

        return response()->json($this->comoJson($concepto->fresh(['tarifas', 'baseConcepto']), CicloNomina::hoy(), 0));
    }

    /**
     * POST /api/nomina/conceptos-empleador/{id}/tarifas
     *
     * El porcentaje desde una fecha: así se aplica un cambio de ley. Los
     * ciclos que cierran antes siguen con el de antes; los pagados no se
     * mueven. Si ya había uno con esa misma fecha, se corrige.
     */
    public function agregarTarifa(Request $request, int $id)
    {
        $concepto = NominaConceptoEmpleador::findOrFail($id);

        $data = $request->validate([
            'porcentaje' => 'required|numeric|min:0|max:100',
            'desde'      => 'required|date',
            'nota'       => 'nullable|string|max:200',
        ], [
            'porcentaje.required' => 'Falta el porcentaje.',
            'desde.required'      => '¿Desde cuándo rige?',
        ]);

        NominaConceptoTarifa::updateOrCreate(
            ['concepto_id' => $concepto->id, 'desde' => CicloNomina::fecha($data['desde'])->toDateString()],
            ['porcentaje' => $data['porcentaje'], 'nota' => $data['nota'] ?? null, 'creado_por' => $request->user()->id],
        );

        CostoEmpleador::olvidarCache();

        return response()->json($this->comoJson($concepto->fresh(['tarifas', 'baseConcepto']), CicloNomina::hoy(), 0));
    }

    /**
     * DELETE /api/nomina/conceptos-empleador/tarifas/{id}
     *
     * Solo un cambio PROGRAMADO (que todavía no rige) puesto por error. Lo que
     * ya rigió se queda: los ciclos de esas fechas se calcularon con él.
     */
    public function quitarTarifa(int $id)
    {
        $tarifa = NominaConceptoTarifa::findOrFail($id);

        if ($tarifa->desde->toDateString() <= CicloNomina::hoy()->toDateString()) {
            throw ValidationException::withMessages([
                'tarifa' => ['Ese porcentaje ya rige: para cambiarlo, agrega uno nuevo con la fecha desde la que cambia.'],
            ]);
        }

        $tarifa->delete();
        CostoEmpleador::olvidarCache();

        return response()->json(['ok' => true]);
    }

    /** PUT /api/nomina/conceptos-empleador/ajustes — si el bono es salario. */
    public function guardarAjustes(Request $request)
    {
        $data = $request->validate(['bono_es_salario' => 'required|boolean']);
        CostoEmpleador::guardarBonoEsSalario($data['bono_es_salario']);

        return response()->json(['bono_es_salario' => CostoEmpleador::bonoEsSalario()]);
    }

    private function comoJson(NominaConceptoEmpleador $c, $hoy, int $excepciones): array
    {
        $hoyStr = $hoy->toDateString();
        $proxima = $c->tarifas->first(fn ($t) => $t->desde->toDateString() > $hoyStr);

        return [
            'id'                 => $c->id,
            'clave'              => $c->clave,
            'nombre'             => $c->nombre,
            'grupo'              => $c->grupo,
            'base'               => $c->base,
            'base_concepto_id'   => $c->base_concepto_id,
            'base_concepto'      => $c->baseConcepto?->nombre,
            'aplica_por_defecto' => (bool) $c->aplica_por_defecto,
            'activo'             => (bool) $c->activo,
            'orden'              => (int) $c->orden,
            'nota'               => $c->nota,
            'porcentaje'         => $c->porcentajeEn($hoy),
            // Un cambio de ley ya cargado que todavía no rige.
            'proximo'            => $proxima ? [
                'id' => $proxima->id, 'porcentaje' => (float) $proxima->porcentaje, 'desde' => $proxima->desde->toDateString(),
            ] : null,
            'tarifas'            => $c->tarifas->map(fn ($t) => [
                'id'         => $t->id,
                'porcentaje' => (float) $t->porcentaje,
                'desde'      => $t->desde->toDateString(),
                'nota'       => $t->nota,
            ])->values(),
            // A cuántos trabajadores se les puso otra cosa en su ficha.
            'excepciones'        => $excepciones,
        ];
    }

    private function claveLibre(string $nombre): string
    {
        $base  = Str::slug($nombre, '_') ?: 'concepto';
        $clave = $base;
        for ($i = 2; NominaConceptoEmpleador::where('clave', $clave)->exists(); $i++) {
            $clave = "{$base}_{$i}";
        }

        return $clave;
    }
}
