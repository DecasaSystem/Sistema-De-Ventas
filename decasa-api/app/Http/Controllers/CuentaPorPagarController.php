<?php

namespace App\Http\Controllers;

use App\Models\CuentaPorPagar;
use App\Models\Gasto;
use App\Services\Finanzas\Cierres;
use App\Services\Finanzas\Periodo;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Facturas de proveedores a crédito (30/60 días). La factura es gasto del mes
 * en que llegó; cada abono es un `Gasto` atado a ella, que Finanzas cuenta
 * solo como caja. Salen en el calendario de pagos con su vencimiento.
 * No se borran: se anulan con motivo.
 */
class CuentaPorPagarController extends Controller
{
    /** GET /api/finanzas/cuentas-por-pagar?estado=pendiente */
    public function index(Request $request)
    {
        $q = CuentaPorPagar::with(['proveedor:id,nombre', 'categoria:id,nombre,icono', 'tienda:id,nombre', 'abonos'])
            ->when($request->query('estado', 'pendiente') !== 'todas', fn ($q) => $q->where('estado', $request->query('estado', 'pendiente')))
            ->orderBy('fecha_vencimiento')->limit(200);

        return response()->json($q->get()->map(fn ($f) => $this->comoJson($f)));
    }

    /** POST /api/finanzas/cuentas-por-pagar */
    public function store(Request $request)
    {
        $data = $request->validate([
            'proveedor_id'       => 'nullable|exists:proveedores,id',
            'proveedor_nombre'   => 'nullable|required_without:proveedor_id|string|max:120',
            'concepto'           => 'required|string|max:160',
            'numero_factura'     => 'nullable|string|max:60',
            'categoria_gasto_id' => 'required|exists:categorias_gasto,id',
            'tienda_id'          => 'nullable|exists:tiendas,id',
            'monto'              => 'required|numeric|min:1',
            'fecha_factura'      => 'required|date',
            'fecha_vencimiento'  => 'required|date|after_or_equal:fecha_factura',
            'comprobante_fotos'  => 'nullable|array|max:10',
            'notas'              => 'nullable|string|max:2000',
        ], [
            'proveedor_nombre.required_without' => 'Elige el proveedor o escribe su nombre.',
            'fecha_vencimiento.after_or_equal'  => 'El vencimiento no puede ser antes de la factura.',
        ]);
        Cierres::exigirAbierto(Periodo::mesDe($data['fecha_factura']));

        $f = CuentaPorPagar::create($data + ['estado' => 'pendiente', 'registrado_por' => $request->user()->id]);

        return response()->json($this->comoJson($f->load(['proveedor', 'categoria', 'tienda', 'abonos'])), 201);
    }

    /**
     * POST /api/finanzas/cuentas-por-pagar/{id}/pagar — un abono (o el total).
     * Queda como gasto atado a la factura: caja ese día, sin volver a contar el gasto.
     */
    public function pagar(Request $request, int $id)
    {
        $f = CuentaPorPagar::with('abonos')->findOrFail($id);
        if ($f->estado !== 'pendiente') {
            throw ValidationException::withMessages(['estado' => ['Esa factura no está pendiente.']]);
        }

        $data = $request->validate([
            'monto'             => 'nullable|numeric|min:1',
            'fecha_pago'        => 'nullable|date',
            'metodo_pago'       => ['nullable', Rule::in(Gasto::METODOS)],
            'comprobante_fotos' => 'nullable|array|max:10',
        ]);
        $saldo = $f->saldo();
        $monto = round((float) ($data['monto'] ?? $saldo), 2);
        if ($monto > $saldo + 0.5) {
            throw ValidationException::withMessages(['monto' => ['Se deben ' . number_format($saldo, 0, ',', '.') . '.']]);
        }
        $fecha = Carbon::parse($data['fecha_pago'] ?? Periodo::hoy()->toDateString())->toDateString();

        DB::transaction(function () use ($f, $monto, $fecha, $data, $request, $saldo) {
            Gasto::create([
                'categoria_gasto_id'  => $f->categoria_gasto_id,
                'cuenta_por_pagar_id' => $f->id,
                'tienda_id'           => $f->tienda_id,
                'proveedor_id'        => $f->proveedor_id,
                'concepto'            => 'Pago factura ' . ($f->numero_factura ?: $f->concepto),
                'monto'               => $monto,
                'estado'              => Gasto::PAGADO,
                'fecha_pago'          => $fecha,
                'cubre_desde'         => $f->fecha_factura,
                'cubre_hasta'         => $f->fecha_factura,
                'metodo_pago'         => $data['metodo_pago'] ?? 'transferencia',
                'comprobante_fotos'   => $data['comprobante_fotos'] ?? null,
                'comprobante_url'     => $data['comprobante_fotos'][0] ?? null,
                'registrado_por'      => $request->user()->id,
            ]);
            if ($monto >= $saldo - 0.5) $f->update(['estado' => 'pagada']);
        });

        return response()->json($this->comoJson($f->fresh(['proveedor', 'categoria', 'tienda', 'abonos'])));
    }

    /** POST /api/finanzas/cuentas-por-pagar/{id}/anular */
    public function anular(Request $request, int $id)
    {
        $data = $request->validate(['motivo' => 'required|string|max:200']);
        $f = CuentaPorPagar::with('abonos')->findOrFail($id);
        if ($f->pagado() > 0) {
            throw ValidationException::withMessages(['estado' => ['Ya tiene abonos: anula primero los abonos en Gastos.']]);
        }
        Cierres::exigirAbierto(Periodo::mesDe($f->fecha_factura));
        $f->update(['estado' => 'anulada', 'motivo_anulacion' => $data['motivo']]);

        return response()->json(['ok' => true]);
    }

    private function comoJson(CuentaPorPagar $f): array
    {
        $hoy = Periodo::hoy()->toDateString();

        return [
            'id'                 => $f->id,
            'proveedor_id'       => $f->proveedor_id,
            'proveedor'          => $f->nombreProveedor(),
            'concepto'           => $f->concepto,
            'numero_factura'     => $f->numero_factura,
            'categoria_gasto_id' => $f->categoria_gasto_id,
            'categoria'          => $f->categoria?->nombre,
            'tienda'             => $f->tienda?->nombre,
            'monto'              => (float) $f->monto,
            'pagado'             => $f->pagado(),
            'saldo'              => $f->saldo(),
            'fecha_factura'      => $f->fecha_factura->toDateString(),
            'fecha_vencimiento'  => $f->fecha_vencimiento->toDateString(),
            'vencida'            => $f->estado === 'pendiente' && $f->fecha_vencimiento->toDateString() < $hoy,
            'estado'             => $f->estado,
            'comprobante_fotos'  => $f->comprobante_fotos ?? [],
            'notas'              => $f->notas,
        ];
    }
}
