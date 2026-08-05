<?php

namespace App\Http\Controllers;

use App\Models\Orden;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Fijar una orden para tenerla de primeras en la lista.
 *
 * Es un marcador personal: cada quien arma el suyo y no ve el de los demás.
 */
class OrdenFijadaController extends Controller
{
    /** POST /api/ordenes/{id}/fijar */
    public function fijar(Request $request, int $id)
    {
        Orden::findOrFail($id);

        // Sin duplicar si la fija dos veces (doble toque, dos pestañas...)
        DB::table('orden_fijadas')->updateOrInsert(
            ['orden_id' => $id, 'usuario_id' => $request->user()->id],
            ['updated_at' => now(), 'created_at' => now()],
        );

        return response()->json(['fijada' => true]);
    }

    /** DELETE /api/ordenes/{id}/fijar */
    public function quitar(Request $request, int $id)
    {
        DB::table('orden_fijadas')
            ->where('orden_id', $id)
            ->where('usuario_id', $request->user()->id)
            ->delete();

        return response()->json(['fijada' => false]);
    }
}
