<?php

namespace App\Http\Controllers;

use App\Services\CicloNomina;
use App\Services\TrabajoDelTaller;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Nómina → Trabajo del taller: lo que hizo cada trabajador en un periodo, para
 * imprimirle su hoja (antes la llenaba él a mano). Sección aparte: no toca
 * pagos ni la bonificación por producción. Ver `TrabajoDelTaller`.
 *
 * El periodo (quincena, mes, cada 20 días, semana o personalizado) lo arma la
 * pantalla con la regla de los ciclos de nómina y manda `desde`/`hasta`.
 */
class NominaTrabajoTallerController extends Controller
{
    /** @return array{0:string,1:string,2:string} desde, hasta y el nombre del periodo */
    private function periodo(Request $request): array
    {
        // Con `periodicidad` + `fecha`, el ciclo que contiene esa fecha, con la
        // misma regla de los pagos (el de cada 20 días tiene un ancla: no se
        // repite en la pantalla). Ir al anterior/siguiente = pedir el día antes
        // de `desde` o el después de `hasta`.
        if ($request->filled('periodicidad')) {
            $p = $request->validate([
                'periodicidad' => 'required|in:semanal,quincenal,20_dias,mensual',
                'fecha'        => 'nullable|date',
            ]);
            [$ini, $fin] = CicloNomina::rango($p['periodicidad'], $p['fecha'] ?? \App\Services\RangoFechas::hoyTexto());

            return [$ini->toDateString(), $fin->toDateString(), CicloNomina::nombre($p['periodicidad'], $ini, $fin)];
        }

        $data = $request->validate([
            'desde' => 'required|date',
            'hasta' => 'required|date|after_or_equal:desde',
        ], [
            'desde.required'       => 'Escoge desde qué fecha.',
            'hasta.required'       => 'Escoge hasta qué fecha.',
            'hasta.after_or_equal' => 'La fecha final no puede ser antes de la inicial.',
        ]);

        $ini = Carbon::parse($data['desde'])->startOfDay();
        $fin = Carbon::parse($data['hasta'])->startOfDay();

        if ($ini->diffInDays($fin) > 370) {
            abort(422, 'El periodo puede ser de máximo un año.');
        }

        // Un mes completo se nombra "Noviembre de 2026"; lo demás, "1 al 15 de
        // noviembre de 2026" (CicloNomina ya sabe nombrar los dos).
        $mesEntero = $ini->day === 1 && $fin->isSameDay($ini->copy()->endOfMonth()->startOfDay());
        $nombre    = CicloNomina::nombre($mesEntero ? 'mensual' : 'quincenal', $ini, $fin);

        return [$ini->toDateString(), $fin->toDateString(), $nombre];
    }

    /** GET /api/nomina/trabajo-taller?desde=&hasta= — lista con totales y el detalle de cada uno. */
    public function index(Request $request)
    {
        [$desde, $hasta, $nombre] = $this->periodo($request);

        return response()->json([
            'desde'    => $desde,
            'hasta'    => $hasta,
            'periodo'  => $nombre,
            'personas' => TrabajoDelTaller::porPersona($desde, $hasta),
        ]);
    }

    /** GET /api/nomina/trabajo-taller/pdf?desde=&hasta=[&usuario_id=] — una persona o todas. */
    public function pdf(Request $request)
    {
        [$desde, $hasta, $nombre] = $this->periodo($request);
        $usuarioId = $request->integer('usuario_id') ?: null;

        $personas = TrabajoDelTaller::porPersona($desde, $hasta, $usuarioId);

        // Imprimir la de alguien que no trabajó en el periodo: se le saca la
        // hoja vacía (solo los renglones a mano), que también sirve.
        if ($usuarioId && $personas->isEmpty()) {
            $u = \App\Models\Usuario::find($usuarioId);
            if (! $u) {
                abort(404, 'Ese trabajador no existe.');
            }
            $personas = collect([[
                'usuario_id' => $u->id, 'nombre' => $u->nombre,
                'piezas' => 0, 'trabajos' => 0, 'grupos' => [],
            ]]);
        }

        if ($personas->isEmpty()) {
            abort(422, 'Nadie terminó pasos en el taller en ese periodo.');
        }

        $archivo = $usuarioId
            ? 'trabajo_' . \Illuminate\Support\Str::slug($personas->first()['nombre']) . "_{$desde}.pdf"
            : "trabajo_del_taller_{$desde}.pdf";

        // Varias personas = varias páginas: que no se corte a la mitad.
        set_time_limit(120);

        return Pdf::loadView('pdf.trabajo_taller', [
            'personas' => $personas,
            'periodo'  => $nombre,
        ])->setPaper('letter')->download($archivo);
    }
}
