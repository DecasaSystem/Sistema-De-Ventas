<?php

namespace App\Http\Controllers;

use App\Models\NominaPrestamo;
use Illuminate\Http\Request;

/**
 * Préstamos a trabajadores, que se descuentan solos por cuotas.
 *
 * El caso: "présteme $200.000 y me los descuenta en dos meses". Si cobra
 * quincenal, son cuatro cuotas de $50.000, una por pago, sin que nadie tenga
 * que acordarse cada quincena.
 */
class NominaPrestamoController extends Controller
{
    /** GET /api/nomina/prestamos?usuario_id=&incluir_saldados=1 */
    public function index(Request $request)
    {
        $q = NominaPrestamo::with(['trabajador:id,nombre', 'cuotasPagadas'])
            ->orderByDesc('fecha');

        if ($uid = $request->query('usuario_id')) {
            $q->where('usuario_id', $uid);
        }

        $lista = $q->get()
            ->filter(fn (NominaPrestamo $p) => $request->boolean('incluir_saldados') || ! $p->saldado())
            ->map(fn (NominaPrestamo $p) => $this->comoJson($p))
            ->values();

        return response()->json($lista);
    }

    /** POST /api/nomina/prestamos */
    public function store(Request $request)
    {
        $data = $request->validate([
            'usuario_id'  => 'required|exists:usuarios,id',
            'monto'       => 'required|numeric|min:1',
            'cuotas'      => 'required|integer|min:1|max:60',
            'motivo'      => 'nullable|string|max:160',
            'fecha'       => 'nullable|date',
            // Se puede fijar a mano; si no, sale de dividir el monto.
            'valor_cuota' => 'nullable|numeric|min:1',
        ]);

        $monto  = (float) $data['monto'];
        $cuotas = (int) $data['cuotas'];

        // Se redondea hacia arriba para que la última cuota sea la corta y no
        // quede un saldo de $3 arrastrándose para siempre. El modelo ya recorta
        // la última al saldo real.
        $valorCuota = isset($data['valor_cuota'])
            ? (float) $data['valor_cuota']
            : (float) ceil($monto / $cuotas);

        $prestamo = NominaPrestamo::create([
            'usuario_id'  => $data['usuario_id'],
            'motivo'      => $data['motivo'] ?? null,
            'monto'       => $monto,
            'cuotas'      => $cuotas,
            'valor_cuota' => $valorCuota,
            'fecha'       => $data['fecha'] ?? now()->toDateString(),
            'creado_por'  => $request->user()->id,
            'activo'      => true,
        ]);

        return response()->json($this->comoJson($prestamo->load('cuotasPagadas', 'trabajador:id,nombre')), 201);
    }

    /**
     * PATCH /api/nomina/prestamos/{id}
     * Sirve para pausarlo —alguien en licencia al que no se le descuenta— o
     * para corregir la cuota. El monto prestado no se toca: eso ya pasó.
     */
    public function update(Request $request, int $id)
    {
        $prestamo = NominaPrestamo::with('cuotasPagadas')->findOrFail($id);

        $data = $request->validate([
            'activo'      => 'sometimes|boolean',
            'valor_cuota' => 'sometimes|numeric|min:1',
            'motivo'      => 'sometimes|nullable|string|max:160',
        ]);

        $prestamo->update($data);

        return response()->json($this->comoJson($prestamo->fresh('cuotasPagadas')));
    }

    /**
     * DELETE /api/nomina/prestamos/{id}
     *
     * Solo si no se ha descontado nada: una vez que hay cuotas cobradas,
     * borrarlo dejaría esos descuentos sin explicación en los pagos ya hechos.
     * Para dejar de cobrarlo, se pausa.
     */
    public function destroy(int $id)
    {
        $prestamo = NominaPrestamo::with('cuotasPagadas')->findOrFail($id);

        if ($prestamo->cuotasPagadas->isNotEmpty()) {
            return response()->json([
                'message' => 'Ya se le descontaron cuotas de este préstamo, así que no se puede borrar '
                           . 'sin dejar esos descuentos sin explicación. Pausalo para dejar de cobrarlo.',
            ], 422);
        }

        $prestamo->delete();

        return response()->json(['ok' => true, 'message' => 'Préstamo eliminado.']);
    }

    private function comoJson(NominaPrestamo $p): array
    {
        return [
            'id'            => $p->id,
            'usuario_id'    => $p->usuario_id,
            'trabajador'    => $p->trabajador?->nombre,
            'motivo'        => $p->motivo,
            'monto'         => (float) $p->monto,
            'cuotas'        => (int) $p->cuotas,
            'valor_cuota'   => (float) $p->valor_cuota,
            'fecha'         => $p->fecha->toDateString(),
            'activo'        => (bool) $p->activo,
            'abonado'       => $p->abonado(),
            'saldo'         => $p->saldo(),
            'saldado'       => $p->saldado(),
            'cuotas_pagadas' => $p->cuotasPagadas->count(),
        ];
    }
}
