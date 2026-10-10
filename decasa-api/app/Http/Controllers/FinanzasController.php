<?php

namespace App\Http\Controllers;

use App\Exports\ReporteExport;
use App\Services\Finanzas\CalendarioPagos;
use App\Services\Finanzas\ConfigFinanzas;
use App\Services\Finanzas\EstadoResultados;
use App\Services\Finanzas\FlujoDeCaja;
use App\Services\Finanzas\FuenteGarantiasYCanales;
use App\Services\Finanzas\Indicadores;
use App\Services\Finanzas\Periodo;
use App\Services\Finanzas\Proyeccion;
use App\Services\Finanzas\RentabilidadTiendas;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * El módulo de Finanzas: lo que entra y lo que sale de la empresa
 * (docs/plan-gestion-financiera.md). Todo de lectura; los gastos se manejan
 * en GastoController. Solo supervisores con `acceso_finanzas` (las rutas).
 *
 * Las cifras salen de los módulos dueños de cada dato —Reportes, Nómina,
 * Comisiones— por los servicios de App\Services\Finanzas: aquí no se
 * recalcula nada por cuenta propia.
 */
class FinanzasController extends Controller
{
    /**
     * GET /api/finanzas/resumen?mes=YYYY-MM — lo que se ve al entrar: el
     * estado de resultados del mes, cómo va contra el anterior, los
     * indicadores con el semáforo y lo próximo por pagar.
     */
    public function resumen(Request $request)
    {
        $mes = $this->mes($request);
        $anteriorMes = Periodo::sumarMeses($mes, -1);
        [$anterior, $actual] = EstadoResultados::meses($anteriorMes, $mes)['meses'];

        return response()->json([
            'mes'         => $actual,
            'anterior'    => [
                'mes' => $anterior['mes'], 'nombre' => $anterior['nombre'], 'ventas' => $anterior['ventas'],
                'utilidad_operativa' => $anterior['utilidad_operativa'], 'cobrado' => $anterior['cobrado'],
            ],
            'indicadores' => Indicadores::delMes($mes, $actual, $anterior),
            'proximos'    => array_slice(CalendarioPagos::proximos(20), 0, 8),
            'garantias'   => FuenteGarantiasYCanales::garantias($mes),
            'actualizado' => now(Periodo::TZ)->toIso8601String(),
        ]);
    }

    /** GET /api/finanzas/estado-resultados?desde=YYYY-MM&hasta=YYYY-MM (hasta 24 meses) */
    public function estadoResultados(Request $request)
    {
        [$desde, $hasta] = $this->rango($request, 6);

        return response()->json(EstadoResultados::meses($desde, $hasta));
    }

    /** GET /api/finanzas/flujo-caja?desde=&hasta= — por mes, y las próximas 13 semanas. */
    public function flujoCaja(Request $request)
    {
        [$desde, $hasta] = $this->rango($request, 6);

        return response()->json(FlujoDeCaja::meses($desde, $hasta) + [
            'semanal' => Proyeccion::flujoSemanal(13),
        ]);
    }

    /** GET /api/finanzas/calendario?dias=45 */
    public function calendario(Request $request)
    {
        $dias = min(120, max(7, (int) $request->query('dias', 45)));

        return response()->json(CalendarioPagos::proximos($dias));
    }

    /** GET /api/finanzas/proyeccion?meses=3 */
    public function proyeccion(Request $request)
    {
        $meses = min(6, max(1, (int) $request->query('meses', 3)));

        return response()->json(Proyeccion::resultados($meses) + [
            'punto_equilibrio' => [
                'costos_fijos' => Proyeccion::proporciones()['costos_fijos_promedio'],
                'margen_contribucion' => Proyeccion::proporciones()['margen_contribucion'],
            ],
        ]);
    }

    /** GET /api/finanzas/por-tienda?mes=YYYY-MM */
    public function porTienda(Request $request)
    {
        return response()->json(RentabilidadTiendas::mes($this->mes($request)));
    }

    /** GET /api/finanzas/por-canal?mes=YYYY-MM — ventas por canal contra la publicidad de cada uno. */
    public function porCanal(Request $request)
    {
        return response()->json(FuenteGarantiasYCanales::canales($this->mes($request)));
    }

    /** GET /api/finanzas/cierres */
    public function cierres()
    {
        return response()->json(\App\Models\CierreFinanciero::with('cerradoPor:id,nombre')->orderByDesc('mes')->get()
            ->map(fn ($c) => [
                'mes' => $c->mes, 'nombre' => \App\Services\Finanzas\Periodo::nombre($c->mes), 'estado' => $c->estado,
                'cerrado_por' => $c->cerradoPor?->nombre, 'cerrado_at' => $c->cerrado_at?->toIso8601String(),
                'reabierto_at' => $c->reabierto_at?->toIso8601String(), 'motivo_reapertura' => $c->motivo_reapertura,
                'utilidad' => $c->snapshot['utilidad_operativa'] ?? null,
            ]));
    }

    /** POST /api/finanzas/cierres — cerrar un mes que ya terminó. */
    public function cerrarMes(Request $request)
    {
        $data = $request->validate(['mes' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $c = \App\Services\Finanzas\Cierres::cerrar($data['mes'], $request->user()->id);

        return response()->json(['mes' => $c->mes, 'estado' => $c->estado], 201);
    }

    /** POST /api/finanzas/cierres/{mes}/reabrir */
    public function reabrirMes(Request $request, string $mes)
    {
        $data = $request->validate(['motivo' => 'required|string|max:200'], ['motivo.required' => '¿Por qué se reabre?']);
        \App\Services\Finanzas\Cierres::reabrir($mes, $data['motivo'], $request->user()->id);

        return response()->json(['ok' => true]);
    }

    /** GET /api/finanzas/ajustes */
    public function ajustes()
    {
        return response()->json(ConfigFinanzas::todo());
    }

    /** PUT /api/finanzas/ajustes */
    public function guardarAjustes(Request $request)
    {
        $data = $request->validate([
            'iva_pct'                => 'sometimes|numeric|min:0|max:100',
            'fv2_sin_iva_no_gravada' => 'sometimes|boolean',
            'periodo_iva'            => 'sometimes|in:bimestral,cuatrimestral',
            'franquicia_pct'         => 'sometimes|numeric|min:0|max:100',
            'saldo_inicial'          => 'sometimes|nullable|numeric',
            'saldo_fecha'            => ['sometimes', 'nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'reparto_generales'      => 'sometimes|in:ventas,partes_iguales,ninguno',
            'umbral_nomina_pct'      => 'sometimes|numeric|min:0|max:100',
            'umbral_margen_pct'      => 'sometimes|numeric|min:-100|max:100',
            'usar_costo_fichas'      => 'sometimes|boolean',
        ], ['saldo_fecha.regex' => 'El mes del saldo va como AAAA-MM.']);

        return response()->json(ConfigFinanzas::guardar($data));
    }

    /** GET /api/finanzas/exportar?desde=&hasta= — el estado de resultados en Excel, para el contador. */
    public function exportar(Request $request)
    {
        [$desde, $hasta] = $this->rango($request, 12);
        $er = EstadoResultados::meses($desde, $hasta);

        $lineas = [
            ['Ventas (con IVA)',          'ventas'],
            ['IVA incluido',              'iva'],
            ['Ingresos netos',            'ingresos_netos'],
            ['Materiales (estimado)',     'costo_produccion.monto'],
            ['Utilidad bruta',            'utilidad_bruta'],
            ['Nómina: sueldos',           'nomina.sueldos'],
            ['Nómina: aportes',           'nomina.aportes'],
            ['Nómina: prestaciones',      'nomina.prestaciones'],
            ['Comisiones',                'comisiones.total'],
            ['Gastos fijos',              'gastos.fijos'],
            ['Gastos variables',          'gastos.variables'],
            ['Gastos financieros',        'financieros.total'],
            ['Utilidad operativa',        'utilidad_operativa'],
            ['Cobrado en el mes (caja)',  'cobrado'],
        ];

        $rows = collect($lineas)->map(fn ($l) => array_merge(
            [$l[0]],
            array_map(fn ($m) => round((float) data_get($m, $l[1], 0)), $er['meses'])
        ));

        return Excel::download(
            new ReporteExport($rows, array_merge(['Concepto'], array_column($er['meses'], 'nombre')),
                "Estado de resultados {$desde} a {$hasta}", [],
                'Devengado. Materiales estimados con fichas técnicas; comisiones no pagadas al día de hoy.'),
            "estado_resultados_{$desde}_{$hasta}.xlsx"
        );
    }

    private function mes(Request $request): string
    {
        $mes = $request->query('mes');

        return Periodo::valido($mes) ? $mes : Periodo::mesActual();
    }

    /** @return array{0: string, 1: string} */
    private function rango(Request $request, int $porDefecto): array
    {
        $hasta = Periodo::valido($request->query('hasta')) ? $request->query('hasta') : Periodo::mesActual();
        $desde = Periodo::valido($request->query('desde')) ? $request->query('desde') : Periodo::sumarMeses($hasta, -($porDefecto - 1));
        if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];
        // Tope de 24 meses: cada mes son varias cuentas.
        if (count(Periodo::meses($desde, $hasta)) > 24) $desde = Periodo::sumarMeses($hasta, -23);

        return [$desde, $hasta];
    }
}
