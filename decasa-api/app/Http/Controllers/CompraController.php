<?php

namespace App\Http\Controllers;

use App\Models\Compra;
use Illuminate\Http\Request;

/**
 * La lista de compras: qué hace falta, quién lo pidió, y cuando alguien lo
 * compra — quién, cuánto costó, cuándo y la factura. Es de todos, como la
 * libreta de Proveedores: cualquiera que inició sesión la lee, pide algo o
 * marca que ya lo compró. Borrar un pedido pendiente (se pidió por error, ya
 * no hace falta) queda para el supervisor; una vez comprado, la fila es
 * historial y no se borra — es el registro de en qué se gastó la plata.
 */
class CompraController extends Controller
{
    private function comoJson(Compra $c): array
    {
        return [
            'id'                => $c->id,
            'item'              => $c->item,
            'cantidad'          => $c->cantidad,
            'notas'             => $c->notas,
            'estado'            => $c->estado,
            'solicitado_por'    => $c->solicitante?->nombre,
            'solicitado_en'     => $c->created_at->toIso8601String(),
            'comprador_nombre'  => $c->comprador_nombre,
            'precio'            => $c->precio !== null ? (float) $c->precio : null,
            'fecha_compra'      => $c->fecha_compra?->toDateString(),
            'factura_foto_url'  => $c->factura_foto_url,
            'registrado_por'    => $c->registradoPor?->nombre,
            'comprado_en'       => $c->estaComprado() ? $c->updated_at->toIso8601String() : null,
        ];
    }

    /** GET /api/compras?estado=pendiente|comprado */
    public function index(Request $request)
    {
        $q = Compra::with('solicitante:id,nombre', 'registradoPor:id,nombre');

        if ($estado = $request->query('estado')) {
            $q->where('estado', $estado);
        }

        // Pendientes: lo más antiguo primero (lo que lleva más tiempo
        // esperando sube arriba). Historial: lo más reciente primero.
        $q->orderBy('created_at', $estado === 'pendiente' ? 'asc' : 'desc');

        return response()->json($q->get()->map(fn (Compra $c) => $this->comoJson($c)));
    }

    /** POST /api/compras — pedir algo. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'item'     => 'required|string|max:150',
            'cantidad' => 'nullable|string|max:50',
            'notas'    => 'nullable|string|max:1000',
        ], [
            'item.required' => 'Escribe qué hace falta comprar.',
        ]);

        $compra = Compra::create($data + [
            'solicitado_por_id' => $request->user()->id,
            'estado'            => 'pendiente',
        ]);

        return response()->json($this->comoJson($compra->load('solicitante:id,nombre')), 201);
    }

    /**
     * PATCH /api/compras/{id}/comprar
     *
     * Completa el pedido con lo que se compró de verdad. Una vez marcado no
     * se puede volver a marcar ni editar desde aquí — si algo quedó mal hay
     * que decírselo a un supervisor, que puede borrar un pendiente pero no
     * un comprado.
     */
    public function marcarComprado(Request $request, int $id)
    {
        $compra = Compra::findOrFail($id);

        if ($compra->estaComprado()) {
            return response()->json(['message' => 'Esto ya estaba marcado como comprado.'], 422);
        }

        $data = $request->validate([
            'comprador_nombre' => 'required|string|max:120',
            'precio'           => 'required|numeric|min:0',
            'fecha_compra'     => 'required|date',
            'factura_foto_url' => 'required|string|max:500',
        ], [
            'comprador_nombre.required' => 'Falta quién lo compró.',
            'precio.required'           => 'Falta cuánto costó.',
            'fecha_compra.required'     => 'Falta cuándo se compró.',
            'factura_foto_url.required' => 'Falta la foto de la factura.',
        ]);

        $compra->update($data + [
            'estado'            => 'comprado',
            'registrado_por_id' => $request->user()->id,
        ]);

        return response()->json($this->comoJson($compra->fresh(['solicitante:id,nombre', 'registradoPor:id,nombre'])));
    }

    /**
     * DELETE /api/compras/{id}
     *
     * Solo pendientes, y solo supervisor: una vez comprado es el registro de
     * un gasto real, con foto y todo — no se borra.
     */
    public function destroy(int $id)
    {
        $compra = Compra::findOrFail($id);

        if ($compra->estaComprado()) {
            return response()->json(['message' => 'Ya se compró: es historial y no se puede borrar.'], 422);
        }

        $compra->delete();

        return response()->json(['ok' => true]);
    }
}
