<?php

namespace App\Http\Controllers;

use App\Models\Comision;
use App\Models\MetaTienda;
use App\Models\Orden;
use App\Models\Tienda;
use App\Models\Usuario;
use App\Services\NotificacionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ComisionController extends Controller
{
    // GET /api/comisiones
    public function index(Request $request)
    {
        $usuario    = $request->user();
        $vendedorId = $request->query('vendedor_id');
        $mes        = $request->query('mes'); // 'YYYY-MM'
        $estado     = $request->query('estado'); // pendiente | lista | pagada

        if (! $usuario->acceso_comisiones) {
            return response()->json(['error' => 'Sin acceso'], 403);
        }

        $query = Comision::with(['orden.pagos', 'vendedor:id,nombre', 'tienda:id,nombre', 'pagadaPor:id,nombre'])
            ->orderBy('fecha_disponible', 'asc');

        if ($vendedorId) $query->where('vendedor_id', $vendedorId);
        if ($mes)        $query->where('mes_venta', $mes);
        if ($estado)     $query->where('estado', $estado);

        $comisiones = $query->get();

        // Cargar metas y totales mensuales para cálculo de comisión
        $metas = MetaTienda::all()->keyBy(fn($m) => $m->tienda_id . '_' . $m->mes);

        $totalesMes = DB::table('comisiones')
            ->selectRaw('vendedor_id, tienda_id, mes_venta, SUM(valor_orden) as total')
            ->where('estado', '!=', 'pendiente') // se excluirán las que no hayan confirmado... en realidad todas entran
            ->groupBy('vendedor_id', 'tienda_id', 'mes_venta')
            ->get()
            ->keyBy(fn($r) => $r->vendedor_id . '_' . $r->tienda_id . '_' . $r->mes_venta);

        // Re-calcular usando todos los registros (incluye las que se filtrarán)
        $totalesMes = DB::table('comisiones')
            ->selectRaw('vendedor_id, tienda_id, mes_venta, SUM(valor_orden) as total')
            ->groupBy('vendedor_id', 'tienda_id', 'mes_venta')
            ->get()
            ->keyBy(fn($r) => $r->vendedor_id . '_' . $r->tienda_id . '_' . $r->mes_venta);

        $hoy = Carbon::today();

        $result = $comisiones->map(function ($c) use ($metas, $totalesMes, $hoy) {
            return $this->enriquecer($c, $metas, $totalesMes, $hoy);
        });

        return response()->json($result);
    }

    // GET /api/comisiones/vendedores — lista de vendedores con resumen
    public function vendedores(Request $request)
    {
        $usuario = $request->user();
        if (! $usuario->acceso_comisiones) {
            return response()->json(['error' => 'Sin acceso'], 403);
        }

        $vendedores = Usuario::whereIn('rol', ['vendedor', 'supervisor'])
            ->where('activo', true)
            ->whereHas('comisiones')
            ->select('id', 'nombre', 'tienda_default_id')
            ->with('tiendaDefault:id,nombre')
            ->get()
            ->map(function ($v) {
                $counts = Comision::where('vendedor_id', $v->id)
                    ->selectRaw('estado, COUNT(*) as n')
                    ->groupBy('estado')
                    ->pluck('n', 'estado');
                return [
                    'id'        => $v->id,
                    'nombre'    => $v->nombre,
                    'tienda'    => $v->tiendaDefault?->nombre,
                    'pendiente' => (int) ($counts['pendiente'] ?? 0),
                    'lista'     => (int) ($counts['lista'] ?? 0),
                    'pagada'    => (int) ($counts['pagada'] ?? 0),
                ];
            });

        return response()->json($vendedores);
    }

    // POST /api/comisiones/{id}/pagar
    public function marcarPagada(Request $request, int $id)
    {
        $usuario = $request->user();
        if (! $usuario->acceso_comisiones) {
            return response()->json(['error' => 'Sin acceso'], 403);
        }

        $comision = Comision::findOrFail($id);

        if ($comision->estado === 'pagada') {
            return response()->json(['error' => 'Ya está pagada.'], 409);
        }
        if ($comision->estado !== 'lista') {
            return response()->json(['error' => 'La comisión no está lista para pagar.'], 422);
        }

        $comision->update([
            'estado'     => 'pagada',
            'fecha_pago' => now(),
            'pagada_por' => $usuario->id,
        ]);

        return response()->json($comision->fresh('pagadaPor:id,nombre'));
    }

    // GET /api/comisiones/metas — metas actuales por tienda
    public function getMetas(Request $request)
    {
        $usuario = $request->user();
        if (! $usuario->acceso_comisiones) {
            return response()->json(['error' => 'Sin acceso'], 403);
        }

        $mes     = $request->query('mes', Carbon::now()->format('Y-m'));
        $tiendas = Tienda::where('activa', true)->get();
        $metas   = MetaTienda::where('mes', $mes)->get()->keyBy('tienda_id');

        return response()->json($tiendas->map(fn($t) => [
            'tienda_id' => $t->id,
            'nombre'    => $t->nombre,
            'mes'       => $mes,
            'meta'      => isset($metas[$t->id]) ? (float) $metas[$t->id]->meta : null,
        ]));
    }

    // POST /api/comisiones/metas — guardar/actualizar meta de una tienda en un mes
    public function setMeta(Request $request)
    {
        $usuario = $request->user();
        if (! $usuario->acceso_comisiones) {
            return response()->json(['error' => 'Sin acceso'], 403);
        }

        $data = $request->validate([
            'tienda_id' => 'required|integer|exists:tiendas,id',
            'mes'       => 'required|string|regex:/^\d{4}-\d{2}$/',
            'meta'      => 'required|numeric|min:0',
        ]);

        $meta = MetaTienda::updateOrCreate(
            ['tienda_id' => $data['tienda_id'], 'mes' => $data['mes']],
            ['meta'      => $data['meta']]
        );

        return response()->json($meta);
    }

    // POST /api/comisiones/recalcular — recalcula estados y envía notificaciones
    public function recalcular(Request $request)
    {
        $usuario = $request->user();
        if (! $usuario->acceso_comisiones) {
            return response()->json(['error' => 'Sin acceso'], 403);
        }

        $metas = MetaTienda::all()->keyBy(fn($m) => $m->tienda_id . '_' . $m->mes);

        $totalesMes = DB::table('comisiones')
            ->selectRaw('vendedor_id, tienda_id, mes_venta, SUM(valor_orden) as total')
            ->groupBy('vendedor_id', 'tienda_id', 'mes_venta')
            ->get()
            ->keyBy(fn($r) => $r->vendedor_id . '_' . $r->tienda_id . '_' . $r->mes_venta);

        $hoy        = Carbon::today();
        $actualizadas = 0;
        $notificadas  = 0;

        Comision::with('orden.pagos')->where('estado', '!=', 'pagada')->chunk(100, function ($chunk) use ($metas, $totalesMes, $hoy, &$actualizadas, &$notificadas) {
            foreach ($chunk as $c) {
                $enriquecida = $this->enriquecer($c, $metas, $totalesMes, $hoy);
                $nuevoEstado = $enriquecida['estado_calculado'];

                $cambios = ['monto_comision' => $enriquecida['monto_comision']];

                if ($nuevoEstado !== $c->estado) {
                    $cambios['estado'] = $nuevoEstado;
                    $actualizadas++;
                }

                // Notificar la primera vez que pasa a 'lista'
                if ($nuevoEstado === 'lista' && ! $c->notificado_lista) {
                    $cambios['notificado_lista'] = true;
                    $this->notificarComisionLista($c, $enriquecida);
                    $notificadas++;
                }

                $c->update($cambios);
            }
        });

        return response()->json(['actualizadas' => $actualizadas, 'notificadas' => $notificadas]);
    }

    // Llamar desde OrdenController al confirmar una orden
    public static function crearParaOrden(Orden $orden): void
    {
        if (! $orden->vendedor_id || ! $orden->tienda_id) return;

        Comision::firstOrCreate(
            ['orden_id' => $orden->id],
            [
                'vendedor_id'      => $orden->vendedor_id,
                'tienda_id'        => $orden->tienda_id,
                'mes_venta'        => Carbon::parse($orden->created_at)->format('Y-m'),
                'valor_orden'      => $orden->valor_total,
                'fecha_venta'      => Carbon::parse($orden->created_at)->toDateString(),
                'fecha_disponible' => Carbon::parse($orden->created_at)->addMonth()->toDateString(),
                'estado'           => 'pendiente',
            ]
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────

    private function enriquecer(Comision $c, $metas, $totalesMes, Carbon $hoy): array
    {
        $metaKey  = $c->tienda_id . '_' . $c->mes_venta;
        $meta     = isset($metas[$metaKey]) ? (float) $metas[$metaKey]->meta : 0;

        $totalKey = $c->vendedor_id . '_' . $c->tienda_id . '_' . $c->mes_venta;
        $totalMes = isset($totalesMes[$totalKey]) ? (float) $totalesMes[$totalKey]->total : (float) $c->valor_orden;

        $metaCumplida  = $meta > 0 && $totalMes >= $meta;
        $comisionMes   = $metaCumplida ? ($totalMes - $meta) / 1.19 * 0.05 : 0;
        $montoComision = ($totalMes > 0 && $comisionMes > 0) ? round($comisionMes * ((float) $c->valor_orden / $totalMes)) : 0;

        $pagado    = $c->orden?->pagos?->sum('monto') ?? 0;
        $req50     = $pagado >= ((float) $c->valor_orden * 0.5);
        $reqVencio = $hoy->gte(Carbon::parse($c->fecha_disponible));

        $estadoCalculado = 'pendiente';
        if ($c->estado === 'pagada') {
            $estadoCalculado = 'pagada';
        } elseif ($req50 && $metaCumplida && $reqVencio) {
            $estadoCalculado = 'lista';
        }

        $fechaDisp    = Carbon::parse($c->fecha_disponible);
        $diasRestantes = $hoy->diffInDays($fechaDisp, false); // positivo = queda tiempo, negativo = atrasada
        $atrasada     = $estadoCalculado === 'lista' && $diasRestantes < 0;

        return array_merge($c->toArray(), [
            'monto_comision'   => $montoComision,
            'total_mes'        => $totalMes,
            'meta_tienda'      => $meta,
            'meta_cumplida'    => $metaCumplida,
            'req_50_pct'       => $req50,
            'req_mes_vencido'  => $reqVencio,
            'pct_pagado'       => $c->valor_orden > 0 ? round($pagado / (float) $c->valor_orden * 100) : 0,
            'atrasada'         => $atrasada,
            'dias_restantes'   => (int) $diasRestantes,
            'estado_calculado' => $estadoCalculado,
            'vendedor_nombre'  => $c->vendedor?->nombre,
            'tienda_nombre'    => $c->tienda?->nombre,
            'orden_numero'     => $c->orden?->numero_orden,
            'orden_cliente'    => null, // cargado por la relación orden->cliente si se necesita
        ]);
    }

    private function notificarComisionLista(Comision $c, array $data): void
    {
        $supervisores = Usuario::where('rol', 'supervisor')
            ->where('activo', true)
            ->where('acceso_comisiones', true)
            ->get();

        $titulo  = 'Comisión lista para pagar';
        $mensaje = "La comisión de {$data['vendedor_nombre']} por la orden #{$data['orden_numero']} está lista.";

        foreach ($supervisores as $sup) {
            NotificacionService::crear('comisiones', $titulo, $mensaje, ['comision_id' => $c->id], $sup->id);
        }
    }
}
