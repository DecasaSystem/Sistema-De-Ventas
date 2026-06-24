<?php

namespace App\Http\Controllers;

use App\Models\CajaMovimiento;
use App\Models\Pago;
use App\Models\Tienda;
use Illuminate\Http\Request;

class CajaController extends Controller
{
    private function tiendaId(Request $request): ?int
    {
        $user = auth()->user();

        if ($user->rol === 'supervisor' && $request->filled('tienda_id')) {
            return (int) $request->input('tienda_id');
        }

        return $user->tienda_default_id ? (int) $user->tienda_default_id : null;
    }

    private function esEbanista(): bool
    {
        return auth()->user()?->rol === 'ebanista';
    }

    public function balance(Request $request)
    {
        $user = auth()->user();

        if ($this->esEbanista()) {
            $ingresoVentas = Pago::where('vendedor_id', $user->id)->sum('monto');
            $ingresoManual = CajaMovimiento::where('usuario_id', $user->id)->where('tipo', 'ingreso_manual')->sum('monto');
            $egresos       = CajaMovimiento::where('usuario_id', $user->id)->where('tipo', 'egreso')->sum('monto');

            return response()->json([
                'tienda_id'      => null,
                'balance'        => (float) ($ingresoVentas + $ingresoManual - $egresos),
                'ingreso_ventas' => (float) $ingresoVentas,
                'ingreso_manual' => (float) $ingresoManual,
                'egresos'        => (float) $egresos,
            ]);
        }

        $tiendaId = $this->tiendaId($request);

        if (! $tiendaId) {
            return response()->json([
                'tienda_id'      => null,
                'balance'        => 0,
                'ingreso_ventas' => 0,
                'ingreso_manual' => 0,
                'egresos'        => 0,
            ]);
        }

        $ingresoVentas = Pago::whereHas('orden', fn($q) => $q->where('tienda_id', $tiendaId))
            ->sum('monto');

        $ingresoManual = CajaMovimiento::where('tienda_id', $tiendaId)
            ->where('tipo', 'ingreso_manual')
            ->sum('monto');

        $egresos = CajaMovimiento::where('tienda_id', $tiendaId)
            ->where('tipo', 'egreso')
            ->sum('monto');

        return response()->json([
            'tienda_id'      => $tiendaId,
            'balance'        => (float) ($ingresoVentas + $ingresoManual - $egresos),
            'ingreso_ventas' => (float) $ingresoVentas,
            'ingreso_manual' => (float) $ingresoManual,
            'egresos'        => (float) $egresos,
        ]);
    }

    public function movimientos(Request $request)
    {
        $user   = auth()->user();
        $limite = min((int) $request->input('limite', 60), 200);

        if ($this->esEbanista()) {
            $pagos = Pago::with(['vendedor:id,nombre'])
                ->where('vendedor_id', $user->id)
                ->latest()
                ->limit($limite)
                ->get()
                ->map(fn($p) => [
                    'id'              => 'pago_' . $p->id,
                    'tipo'            => 'ingreso_venta',
                    'monto'           => (float) $p->monto,
                    'concepto'        => 'Venta #' . $p->orden_id,
                    'descripcion'     => $p->notas,
                    'comprobante_url' => null,
                    'usuario'         => $p->vendedor?->nombre,
                    'fecha'           => $p->created_at,
                    'metodo'          => $p->metodo,
                    'tipo_pago'       => $p->tipo,
                ]);

            $manuales = CajaMovimiento::with('usuario:id,nombre')
                ->where('usuario_id', $user->id)
                ->latest()
                ->limit($limite)
                ->get()
                ->map(fn($m) => [
                    'id'              => 'mov_' . $m->id,
                    'tipo'            => $m->tipo,
                    'monto'           => (float) $m->monto,
                    'concepto'        => $m->concepto,
                    'descripcion'     => $m->descripcion,
                    'comprobante_url' => $m->comprobante_url,
                    'usuario'         => $m->usuario?->nombre,
                    'fecha'           => $m->created_at,
                    'metodo'          => null,
                    'tipo_pago'       => null,
                ]);

            return response()->json(
                collect($pagos)->merge($manuales)->sortByDesc('fecha')->values()
            );
        }

        $tiendaId = $this->tiendaId($request);

        if (! $tiendaId) {
            return response()->json([]);
        }

        $pagos = Pago::with(['vendedor:id,nombre'])
            ->whereHas('orden', fn($q) => $q->where('tienda_id', $tiendaId))
            ->latest()
            ->limit($limite)
            ->get()
            ->map(fn($p) => [
                'id'              => 'pago_' . $p->id,
                'tipo'            => 'ingreso_venta',
                'monto'           => (float) $p->monto,
                'concepto'        => 'Venta #' . $p->orden_id,
                'descripcion'     => $p->notas,
                'comprobante_url' => null,
                'usuario'         => $p->vendedor?->nombre,
                'fecha'           => $p->created_at,
                'metodo'          => $p->metodo,
                'tipo_pago'       => $p->tipo,
            ]);

        $manuales = CajaMovimiento::with('usuario:id,nombre')
            ->where('tienda_id', $tiendaId)
            ->latest()
            ->limit($limite)
            ->get()
            ->map(fn($m) => [
                'id'              => 'mov_' . $m->id,
                'tipo'            => $m->tipo,
                'monto'           => (float) $m->monto,
                'concepto'        => $m->concepto,
                'descripcion'     => $m->descripcion,
                'comprobante_url' => $m->comprobante_url,
                'usuario'         => $m->usuario?->nombre,
                'fecha'           => $m->created_at,
                'metodo'          => null,
                'tipo_pago'       => null,
            ]);

        $todos = collect($pagos)
            ->merge($manuales)
            ->sortByDesc('fecha')
            ->values();

        return response()->json($todos);
    }

    public function registrarMovimiento(Request $request)
    {
        $request->validate([
            'tipo'            => 'required|in:ingreso_manual,egreso',
            'monto'           => 'required|numeric|min:0.01',
            'concepto'        => 'required|string|max:255',
            'descripcion'     => 'nullable|string|max:1000',
            'comprobante_url' => 'nullable|string|max:2048',
            'tienda_id'       => 'nullable|integer|exists:tiendas,id',
        ]);

        $tiendaId = $this->esEbanista()
            ? (auth()->user()->tienda_default_id ?? null)
            : $this->tiendaId($request);

        if (! $this->esEbanista() && ! $tiendaId) {
            return response()->json(['message' => 'Usuario sin tienda asignada.'], 422);
        }

        $movimiento = CajaMovimiento::create([
            'tienda_id'       => $tiendaId,
            'usuario_id'      => auth()->id(),
            'tipo'            => $request->tipo,
            'monto'           => $request->monto,
            'concepto'        => $request->concepto,
            'descripcion'     => $request->descripcion,
            'comprobante_url' => $request->comprobante_url,
        ]);

        return response()->json(
            $movimiento->load('usuario:id,nombre'),
            201
        );
    }

    public function eliminarMovimiento(int $id)
    {
        $mov = CajaMovimiento::findOrFail($id);
        $mov->delete();

        return response()->noContent();
    }

    public function resumenTiendas()
    {
        $tiendas = Tienda::where('activa', true)->get();

        $resumen = $tiendas->map(function ($tienda) {
            $ingresoVentas = Pago::whereHas('orden', fn($q) => $q->where('tienda_id', $tienda->id))
                ->sum('monto');

            $ingresoManual = CajaMovimiento::where('tienda_id', $tienda->id)
                ->where('tipo', 'ingreso_manual')
                ->sum('monto');

            $egresos = CajaMovimiento::where('tienda_id', $tienda->id)
                ->where('tipo', 'egreso')
                ->sum('monto');

            return [
                'tienda_id'      => $tienda->id,
                'tienda_nombre'  => $tienda->nombre,
                'balance'        => (float) ($ingresoVentas + $ingresoManual - $egresos),
                'ingreso_ventas' => (float) $ingresoVentas,
                'ingreso_manual' => (float) $ingresoManual,
                'egresos'        => (float) $egresos,
            ];
        });

        return response()->json($resumen);
    }
}
