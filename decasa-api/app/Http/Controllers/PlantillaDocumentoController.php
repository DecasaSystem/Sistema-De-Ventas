<?php

namespace App\Http\Controllers;

use App\Support\EsquemasPlantillas;
use App\Support\PlantillasDocumento;
use Illuminate\Http\Request;

/**
 * Gestión → Documentos: el negocio cambia el texto del anexo de garantías y
 * de la orden de entrega sin pasar por un programador.
 *
 * Solo supervisores (la ruta va en el grupo role:supervisor). Guardar crea
 * una versión nueva; nunca se edita ni se borra una vieja, porque hay anexos
 * firmados con ella.
 */
class PlantillaDocumentoController extends Controller
{
    /** GET /api/plantillas-documento — los documentos y su versión vigente. */
    public function index()
    {
        $lista = array_map(function (string $clave) {
            $historial = PlantillasDocumento::historial($clave);
            $vigente   = $historial[0];

            return [
                ...PlantillasDocumento::info($clave),
                'version'    => $vigente['version'],
                'fabrica'    => $vigente['fabrica'],
                'autor'      => $vigente['autor'],
                'creado_at'  => $vigente['creado_at'],
                'versiones'  => count($historial),
            ];
        }, PlantillasDocumento::claves());

        return response()->json($lista);
    }

    /** GET /api/plantillas-documento/{clave} — el texto vigente y el historial. */
    public function show(string $clave)
    {
        abort_unless(PlantillasDocumento::existe($clave), 404, 'Ese documento no existe.');

        $historial = PlantillasDocumento::historial($clave);

        return response()->json([
            ...PlantillasDocumento::info($clave),
            'vigente'   => $historial[0],
            'historial' => $historial,
        ]);
    }

    /**
     * GET /api/textos-del-negocio
     *
     * Lo que la app necesita para hablarle al cliente con los textos de
     * Gestión: los mensajes de WhatsApp, el nombre de la empresa, la
     * declaración del acta (que se muestra a quien firma la entrega) y los días
     * que vale una cotización. Lo lee cualquiera con sesión: no trae nada
     * interno ni de plata.
     */
    public function textosDelNegocio()
    {
        return response()->json([
            'empresa'    => PlantillasDocumento::empresa(),
            'mensajes'   => PlantillasDocumento::vigente(EsquemasPlantillas::MENSAJES)['contenido'],
            'acta'       => PlantillasDocumento::vigente(EsquemasPlantillas::ACTA)['contenido'],
            'cotizacion' => [
                'dias_vigencia' => PlantillasDocumento::vigente(EsquemasPlantillas::COTIZACION)['contenido']['dias_vigencia'],
            ],
        ]);
    }

    /**
     * PUT /api/plantillas-documento/{clave}  { contenido: {...}, nota? }
     *
     * Guarda el texto como versión nueva. Si no cambió nada, no crea versión.
     */
    public function update(Request $request, string $clave)
    {
        abort_unless(PlantillasDocumento::existe($clave), 404, 'Ese documento no existe.');
        abort_unless(PlantillasDocumento::hayTabla(), 409, 'Falta correr la migración de plantillas de documentos.');

        $data = $request->validate([
            'contenido' => 'required|array',
            'nota'      => 'nullable|string|max:255',
        ]);

        $fila = PlantillasDocumento::guardar($clave, $data['contenido'], $request->user()?->id, $data['nota'] ?? null);

        return response()->json([
            'cambio'  => (bool) $fila,
            'message' => $fila ? "Guardado como versión {$fila->version}." : 'No cambió nada: sigue la misma versión.',
            ...$this->show($clave)->getData(true),
        ]);
    }
}
