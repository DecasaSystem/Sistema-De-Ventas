<?php

namespace App\Http\Controllers;

use App\Models\NominaPrestamo;
use App\Models\NominaPrestamoCuota;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

    /**
     * POST /api/nomina/prestamos/{id}/abonos
     *
     * El trabajador paga por fuera de la nómina: "debe $200.000 que se le
     * descuentan de a $50.000 por quincena, el 10 llega y paga todo". El abono
     * baja el saldo y el siguiente pago de nómina descuenta solo lo que falte
     * (si pagó todo, nada; si pagó una parte, la misma cuota de siempre hasta
     * saldar, así que termina antes). No se recalcula la cuota: si quieren que
     * le descuenten menos por pago, se corrige la cuota aparte.
     */
    public function abonar(Request $request, int $id)
    {
        $data = $request->validate([
            'monto' => 'required|numeric|min:1',
            'fecha' => 'nullable|date',
            'nota'  => 'nullable|string|max:160',
        ]);

        $prestamo = DB::transaction(function () use ($data, $id, $request) {
            // Bloqueado para que un pago de nómina al mismo tiempo no descuente
            // la cuota sobre un saldo que ya no existe.
            $prestamo = NominaPrestamo::lockForUpdate()->findOrFail($id);
            $prestamo->load('cuotasPagadas');
            $saldo = $prestamo->saldo();
            $monto = round((float) $data['monto'], 2);

            if ($saldo <= 0) {
                abort(response()->json(['message' => 'Este préstamo ya está saldado.'], 422));
            }
            if ($monto > $saldo) {
                abort(response()->json([
                    'message' => 'Solo debe $' . number_format($saldo, 0, ',', '.') . ': el abono no puede ser mayor.',
                ], 422));
            }

            $fila = [
                'prestamo_id'    => $prestamo->id,
                'nomina_pago_id' => null,
                'monto'          => $monto,
                'fecha'          => $data['fecha'] ?? now('America/Bogota')->toDateString(),
            ];
            // Los esquemas de prueba viejos no traen estas columnas.
            if (Schema::hasColumn('nomina_prestamo_cuotas', 'tipo')) {
                $fila += [
                    'tipo'           => NominaPrestamoCuota::TIPO_ABONO,
                    'nota'           => $data['nota'] ?? null,
                    'registrado_por' => $request->user()->id,
                ];
            }
            NominaPrestamoCuota::create($fila);

            return $prestamo->fresh(['cuotasPagadas', 'trabajador:id,nombre']);
        });

        return response()->json($this->comoJson($prestamo), 201);
    }

    /**
     * DELETE /api/nomina/prestamos/{id}/abonos/{abonoId}
     * Deshace un abono anotado por error. Solo los abonos por fuera: una cuota
     * descontada en la nómina se deshace anulando ese pago.
     */
    public function quitarAbono(int $id, int $abonoId)
    {
        $abono = NominaPrestamoCuota::where('prestamo_id', $id)->findOrFail($abonoId);

        if (! $abono->esAbono()) {
            return response()->json([
                'message' => 'Esa cuota se descontó en un pago de nómina: se deshace anulando ese pago.',
            ], 422);
        }

        $abono->delete();

        return response()->json($this->comoJson(
            NominaPrestamo::with(['cuotasPagadas', 'trabajador:id,nombre'])->findOrFail($id)
        ));
    }

    private function comoJson(NominaPrestamo $p): array
    {
        $abonos = $p->cuotasPagadas->filter(fn (NominaPrestamoCuota $c) => $c->esAbono());

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
            // "2 de 4 cuotas" cuenta solo lo descontado en la nómina; lo que
            // pagó por fuera va aparte para que se entienda por qué baja el saldo.
            'cuotas_pagadas' => $p->cuotasPagadas->count() - $abonos->count(),
            'abonado_aparte' => round((float) $abonos->sum('monto'), 2),
            'abonos'         => $abonos->sortByDesc('fecha')->map(fn (NominaPrestamoCuota $c) => [
                'id'    => $c->id,
                'monto' => (float) $c->monto,
                'fecha' => $c->fecha?->toDateString(),
                'nota'  => $c->nota,
            ])->values(),
        ];
    }
}
