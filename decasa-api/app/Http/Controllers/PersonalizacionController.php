<?php

namespace App\Http\Controllers;

use App\Models\Herramienta;
use App\Models\Modulo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Lo que cada empresa le cambia al programa: cómo se llaman los módulos, con
 * qué icono salen y qué tiene a mano el asesor para copiar.
 *
 * Leer lo puede cualquiera que haya entrado —son los nombres de su propia
 * pantalla—; cambiarlo, sólo quien administra.
 */
class PersonalizacionController extends Controller
{
    // ── Módulos ───────────────────────────────────────────────────────────────

    /** GET /api/modulos */
    public function modulos()
    {
        return response()->json(
            Modulo::orderBy('orden')->orderBy('nombre')->get(['clave', 'nombre', 'icono', 'visible', 'orden'])
        );
    }

    /**
     * PATCH /api/modulos
     *
     * Llega la lista completa de lo que cambió. La clave viaja para saber a
     * quién se le cambia el nombre, pero nunca se escribe: es lo que el código
     * busca, y renombrarla dejaría el módulo huérfano.
     */
    public function guardarModulos(Request $request)
    {
        $data = $request->validate([
            'modulos'           => 'required|array|min:1',
            'modulos.*.clave'   => 'required|string|exists:modulos,clave',
            'modulos.*.nombre'  => 'required|string|max:60',
            'modulos.*.icono'   => 'required|string|max:60',
            'modulos.*.visible' => 'nullable|boolean',
            'modulos.*.orden'   => 'nullable|integer|min:0|max:9999',
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['modulos'] as $m) {
                Modulo::where('clave', $m['clave'])->update([
                    'nombre'  => trim($m['nombre']),
                    'icono'   => $m['icono'],
                    'visible' => $m['visible'] ?? true,
                    'orden'   => $m['orden']   ?? 0,
                ]);
            }
        });

        return $this->modulos();
    }

    // ── Herramientas ──────────────────────────────────────────────────────────

    /**
     * GET /api/herramientas
     *
     * Para el panel del asesor van sólo las activas; para la pantalla de
     * administración, todas, porque desde ahí se vuelven a encender.
     */
    public function herramientas(Request $request)
    {
        $query = Herramienta::orderBy('seccion')->orderBy('orden')->orderBy('id');

        if (! $request->boolean('todas')) {
            $query->where('activo', true);
        }

        return response()->json($query->get());
    }

    /** POST /api/herramientas */
    public function crearHerramienta(Request $request)
    {
        $data = $this->validarHerramienta($request);

        // Al final de su sección, que es donde uno espera que aparezca lo que
        // acaba de crear.
        $data['orden'] = (int) Herramienta::where('seccion', $data['seccion'])->max('orden') + 10;

        return response()->json(Herramienta::create($data), 201);
    }

    /** PATCH /api/herramientas/{id} */
    public function actualizarHerramienta(Request $request, int $id)
    {
        $herramienta = Herramienta::findOrFail($id);
        $herramienta->update($this->validarHerramienta($request, parcial: true));

        return response()->json($herramienta);
    }

    /** DELETE /api/herramientas/{id} */
    public function eliminarHerramienta(int $id)
    {
        Herramienta::findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    private function validarHerramienta(Request $request, bool $parcial = false): array
    {
        // Al editar sólo se valida lo que venga: la pantalla puede mandar
        // únicamente el interruptor de activo sin reenviar todo el contenido.
        $regla = fn (string $reglas) => $parcial ? "sometimes|{$reglas}" : $reglas;

        return $request->validate([
            'seccion'   => $regla('required|string|max:60'),
            'titulo'    => $regla('required|string|max:120'),
            'tipo'      => $regla('required|in:' . implode(',', Herramienta::TIPOS)),
            'contenido' => $regla('required|string|max:2000'),
            'subtitulo' => 'nullable|string|max:200',
            'icono'     => 'nullable|string|max:60',
            'activo'    => 'nullable|boolean',
            'orden'     => 'nullable|integer|min:0|max:9999',
        ]);
    }
}
