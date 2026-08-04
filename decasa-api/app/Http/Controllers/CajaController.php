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

    /**
     * ¿Este usuario lleva caja propia en vez de la de una tienda?
     *
     * Son los que venden por su cuenta: los vendedores independientes y el
     * ebanista desde la fábrica. Su efectivo es suyo y responden por él.
     */
    private function llevaCajaPropia(): bool
    {
        $u = auth()->user();
        return (bool) ($u?->independiente) || $u?->rol === 'ebanista';
    }

    /** Filtro reutilizable: pagos de quien lleva caja propia. */
    private static function esDeCajaPropia($q)
    {
        return $q->where('independiente', true)->orWhere('rol', 'ebanista');
    }

    /**
     * Pagos en efectivo que entraron a la caja de una tienda.
     *
     * Manda la tienda donde se recibió el dinero, no la de la orden: el cliente
     * puede abonar en otra sede. Los pagos anteriores a que existiera ese campo
     * caen a la tienda de su orden, que es lo que se asumía entonces.
     *
     * Va en un solo sitio a propósito: el saldo, la lista de movimientos y el
     * resumen por tienda deben filtrar igual o el total no cuadra con el detalle.
     */
    private function efectivoDeTienda(int $tiendaId)
    {
        return Pago::where('metodo', 'efectivo')
            ->where(fn($q) => $q->where('tienda_id', $tiendaId)
                ->orWhere(fn($q2) => $q2->whereNull('tienda_id')
                    ->whereHas('orden', fn($q3) => $q3->where('tienda_id', $tiendaId))))
            // Quien lleva caja propia responde por su dinero y lo ve ahí.
            // Contarlo también en la caja de una tienda mostraría la misma
            // plata en dos lugares.
            ->whereDoesntHave('vendedor', fn($q) => self::esDeCajaPropia($q));
    }

    /**
     * Movimientos manuales de la caja de una tienda.
     *
     * Deja por fuera a quien lleva caja propia: su sede está guardada en el
     * movimiento porque la columna no admite nulos, pero su plata es suya y ya
     * se cuenta en su caja. Sin este filtro el mismo egreso salía dos veces —
     * de la caja de Henry y de la de Bodega Fábrica, por ejemplo.
     *
     * Es el mismo criterio que usa efectivoDeTienda() con los pagos.
     */
    private static function movimientosDeTienda(int $tiendaId)
    {
        return CajaMovimiento::where('tienda_id', $tiendaId)
            ->whereDoesntHave('usuario', fn($q) => self::esDeCajaPropia($q));
    }

    private function balancePorUsuario(int $userId): array
    {
        $ingresoVentas = Pago::where('vendedor_id', $userId)->where('metodo', 'efectivo')->sum('monto');
        $ingresoManual = CajaMovimiento::where('usuario_id', $userId)->where('tipo', 'ingreso_manual')->sum('monto');
        $egresos       = CajaMovimiento::where('usuario_id', $userId)->where('tipo', 'egreso')->sum('monto');

        return [
            'tienda_id'      => null,
            'balance'        => (float) ($ingresoVentas + $ingresoManual - $egresos),
            'ingreso_ventas' => (float) $ingresoVentas,
            'ingreso_manual' => (float) $ingresoManual,
            'egresos'        => (float) $egresos,
        ];
    }

    public function balance(Request $request)
    {
        $user = auth()->user();

        if ($this->llevaCajaPropia()) {
            return response()->json($this->balancePorUsuario($user->id));
        }

        if ($user->rol === 'supervisor' && $request->filled('ebanista_id')) {
            return response()->json($this->balancePorUsuario((int) $request->input('ebanista_id')));
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

        $ingresoVentas = $this->efectivoDeTienda($tiendaId)->sum('monto');

        $ingresoManual = self::movimientosDeTienda($tiendaId)
            ->where('tipo', 'ingreso_manual')
            ->sum('monto');

        $egresos = self::movimientosDeTienda($tiendaId)
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

    private function movimientosPorUsuario(int $userId, int $limite): \Illuminate\Support\Collection
    {
        $pagos = Pago::with([
                'vendedor:id,nombre',
                // Para mostrar "#4261" o "FV2-1" en vez del id interno de la tabla
                'orden:id,numero_orden,serie,serie_numero,cotizacion_numero,estado',
            ])
            ->where('vendedor_id', $userId)
            ->where('metodo', 'efectivo')
            ->latest()->limit($limite)->get()
            ->map(fn($p) => [
                'id'              => 'pago_' . $p->id,
                'tipo'            => 'ingreso_venta',
                'monto'           => (float) $p->monto,
                'concepto'        => 'Venta efectivo ' . ($p->orden?->referencia ?? '#' . $p->orden_id),
                'descripcion'     => $p->notas,
                'comprobante_url' => null,
                'usuario'         => $p->vendedor?->nombre,
                'fecha'           => $p->created_at,
                'metodo'          => $p->metodo,
                'tipo_pago'       => $p->tipo,
            ]);

        $manuales = CajaMovimiento::with('usuario:id,nombre')
            ->where('usuario_id', $userId)
            ->latest()->limit($limite)->get()
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

        return collect($pagos)->merge($manuales)->sortByDesc('fecha')->values();
    }

    public function movimientos(Request $request)
    {
        $user   = auth()->user();
        $limite = min((int) $request->input('limite', 60), 200);

        if ($this->llevaCajaPropia()) {
            return response()->json($this->movimientosPorUsuario($user->id, $limite));
        }

        if ($user->rol === 'supervisor' && $request->filled('ebanista_id')) {
            return response()->json($this->movimientosPorUsuario((int) $request->input('ebanista_id'), $limite));
        }

        $tiendaId = $this->tiendaId($request);

        if (! $tiendaId) {
            return response()->json([]);
        }

        $pagos = $this->efectivoDeTienda($tiendaId)
            ->with([
                'vendedor:id,nombre',
                // Para mostrar "#4261" o "FV2-1" en vez del id interno de la tabla
                'orden:id,numero_orden,serie,serie_numero,cotizacion_numero,estado',
            ])
            ->latest()
            ->limit($limite)
            ->get()
            ->map(fn($p) => [
                'id'              => 'pago_' . $p->id,
                'tipo'            => 'ingreso_venta',
                'monto'           => (float) $p->monto,
                'concepto'        => 'Venta efectivo ' . ($p->orden?->referencia ?? '#' . $p->orden_id),
                'descripcion'     => $p->notas,
                'comprobante_url' => null,
                'usuario'         => $p->vendedor?->nombre,
                'fecha'           => $p->created_at,
                'metodo'          => $p->metodo,
                'tipo_pago'       => $p->tipo,
            ]);

        // Mismo filtro que el saldo: la lista y el total tienen que cuadrar.
        $manuales = self::movimientosDeTienda($tiendaId)->with('usuario:id,nombre')
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

        // La columna tienda_id no admite nulos, así que a quien lleva caja
        // propia se le sigue guardando su sede. Lo que evita el doble conteo es
        // que las consultas de la caja de tienda excluyen a esas personas
        // (ver movimientosDeTienda), igual que ya se hacía con los pagos.
        $tiendaId = $this->llevaCajaPropia()
            ? (auth()->user()->tienda_default_id ?? null)
            : $this->tiendaId($request);

        if (! $this->llevaCajaPropia() && ! $tiendaId) {
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
        // La sede de los independientes no es una caja: su plata está en la
        // caja personal de cada uno, que se lista aparte más abajo.
        $tiendas = Tienda::where('activa', true)->where('es_independientes', false)->get();

        $resumen = $tiendas->map(function ($tienda) {
            $ingresoVentas = $this->efectivoDeTienda($tienda->id)->sum('monto');

            $ingresoManual = self::movimientosDeTienda($tienda->id)
                ->where('tipo', 'ingreso_manual')
                ->sum('monto');

            $egresos = self::movimientosDeTienda($tienda->id)
                ->where('tipo', 'egreso')
                ->sum('monto');

            return [
                'tienda_id'      => $tienda->id,
                'usuario_id'     => null,
                'tienda_nombre'  => $tienda->nombre,
                // La fábrica no es una sede de venta al público: se marca para
                // poder distinguirla al comparar tiendas.
                'es_fabrica'     => (bool) $tienda->es_fabrica,
                'es_independiente' => false,
                'balance'        => (float) ($ingresoVentas + $ingresoManual - $egresos),
                'ingreso_ventas' => (float) $ingresoVentas,
                'ingreso_manual' => (float) $ingresoManual,
                'egresos'        => (float) $egresos,
            ];
        });

        // Una fila por cada quien lleva caja propia: su plata no está en
        // ninguna tienda, pero tiene que poder compararse igual que las demás.
        $propias = \App\Models\Usuario::where('activo', true)
            ->where(fn($q) => self::esDeCajaPropia($q))
            ->orderBy('nombre')
            ->get()
            ->map(function ($u) {
                $b = $this->balancePorUsuario($u->id);
                return [
                    'tienda_id'      => null,
                    'usuario_id'     => $u->id,
                    'tienda_nombre'  => $u->nombre,
                    'es_fabrica'     => false,
                    'es_independiente' => true,
                    'balance'        => $b['balance'],
                    'ingreso_ventas' => $b['ingreso_ventas'],
                    'ingreso_manual' => $b['ingreso_manual'],
                    'egresos'        => $b['egresos'],
                ];
            });

        return response()->json($resumen->concat($propias)->values());
    }
}
