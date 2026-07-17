<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FacturacionController extends Controller
{
    public function ordenes(Request $request)
    {
        $usuario = $request->user();

        if (! $usuario->facturacion) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $estado = $request->query('estado', 'todas');
        $search = $request->query('search', '');
        $desde  = $request->query('desde');
        $hasta  = $request->query('hasta');

        $sumPago   = '(SELECT COALESCE(SUM(p.monto), 0)      FROM pagos p WHERE p.orden_id = ordenes.id)';
        $maxPago   = '(SELECT MAX(p.created_at)               FROM pagos p WHERE p.orden_id = ordenes.id)';
        $cntPago   = '(SELECT COUNT(*)                        FROM pagos p WHERE p.orden_id = ordenes.id)';
        $tienePago = '(SELECT COUNT(*) FROM pagos p WHERE p.orden_id = ordenes.id) > 0';

        $query = DB::table('ordenes')
            ->join('clientes', 'clientes.id', '=', 'ordenes.cliente_id')
            ->join('usuarios', 'usuarios.id', '=', 'ordenes.vendedor_id')
            ->join('tiendas', 'tiendas.id', '=', 'ordenes.tienda_id')
            ->where('ordenes.estado', '!=', 'cancelado')
            ->whereRaw($tienePago)
            ->selectRaw("
                ordenes.id,
                ordenes.numero_orden,
                ordenes.estado,
                ordenes.valor_total,
                ordenes.created_at,
                clientes.nombre   AS cliente_nombre,
                clientes.telefono AS cliente_telefono,
                usuarios.nombre   AS vendedor_nombre,
                tiendas.nombre    AS tienda_nombre,
                {$sumPago}        AS total_pagado,
                (ordenes.valor_total - {$sumPago}) AS saldo_pendiente,
                {$maxPago}        AS ultimo_pago,
                {$cntPago}        AS num_pagos
            ");

        if ($search) {
            $raw   = trim($search);
            $sinHash = ltrim($raw, '#');          // quitar # si el usuario escribe "#3"
            $term  = '%' . mb_strtolower($raw) . '%';
            $query->where(function ($q) use ($term, $sinHash) {
                $q->whereRaw('LOWER(clientes.nombre) LIKE ?', [$term]);
                if (is_numeric($sinHash)) {
                    $q->orWhere('ordenes.id', (int) $sinHash)
                      ->orWhere('ordenes.numero_orden', $sinHash);
                }
            });
        }

        if ($desde) {
            $query->whereRaw("{$maxPago} >= ?", [$desde]);
        }
        if ($hasta) {
            $query->whereRaw("{$maxPago} <= ?", [$hasta . ' 23:59:59']);
        }

        if ($estado === 'con_abono') {
            $query->whereRaw("(ordenes.valor_total - {$sumPago}) > 0.01");
        } elseif ($estado === 'pagadas') {
            $query->whereRaw("(ordenes.valor_total - {$sumPago}) <= 0.01");
        }

        $ordenes = $query
            ->orderByRaw("{$maxPago} DESC")
            ->paginate(20);

        return response()->json($ordenes);
    }
}
