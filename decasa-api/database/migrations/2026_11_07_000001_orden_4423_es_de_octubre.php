<?php

use App\Http\Controllers\ComisionController;
use App\Models\Comision;
use App\Models\Orden;
use App\Models\OrdenEdicion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La #4423 (LINA MARIA OTALVARO) es una venta del 31 de octubre que se les
 * olvidó subir y se subió en noviembre. Pedido del usuario, 2026-11-03: que
 * quede del 31 de octubre.
 *
 * La fecha de la venta es `created_at` (comisiones, reportes y metas la leen
 * de ahí, en hora de Bogotá): se pone a mediodía de ese día. El número no se
 * toca (es el consecutivo que le tocó) y los pagos tampoco: el dinero entró
 * cuando entró.
 *
 * La comisión se rehace con la fecha nueva (mes de venta, equipo de ese día,
 * fecha de pago) con la misma puerta que usa editar una orden, y se pide el
 * recálculo de todo para que la venta sume ya a la meta y al pool de octubre.
 * Lo pagado no se toca.
 *
 * Solo corrige esa orden y solo si la encuentra tal cual (ese número, ese
 * cliente, todavía con fecha de noviembre); correrla dos veces no hace nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        $orden = Orden::where('numero_orden', 4423)->whereNull('serie')
            ->where('created_at', '>=', '2026-11-01 05:00:00') // 1 de noviembre en Bogotá
            ->whereHas('cliente', fn ($q) => $q->where('nombre', 'like', '%lina%')->where('nombre', 'like', '%otalvaro%'))
            ->first();
        if (! $orden) {
            echo "[#4423] No se encontró con fecha de noviembre: no se cambió nada.\n";
            return;
        }

        $antes = $orden->created_at->copy()->setTimezone('America/Bogota')->format('d/m/Y');

        DB::table('ordenes')->where('id', $orden->id)->update([
            'created_at' => '2026-10-31 17:00:00', // 12:00 m. en Bogotá
        ]);

        $usuarioId = DB::table('usuarios')->where('rol', 'supervisor')->where('activo', true)->orderBy('id')->value('id')
            ?? $orden->vendedor_id;
        if ($usuarioId) {
            OrdenEdicion::create(['orden_id' => $orden->id, 'usuario_id' => $usuarioId, 'cambios' => [
                ['campo' => 'created_at', 'label' => 'Fecha de la venta', 'antes' => $antes, 'despues' => '31/10/2026'],
            ]]);
        }

        // Las comisiones de una venta se crean una vez (firstOrCreate) y no
        // vuelven a mirar la fecha: sincronizarValorOrden solo la rehace en las
        // restauraciones. Aquí se mueven en su sitio (borrarlas se llevaría en
        // cascada los anticipos ligados). Mismo cálculo de fecha_disponible que
        // ComisionController::calcularFechaDisponible.
        foreach (Comision::where('orden_id', $orden->id)->where('estado', '!=', 'pagada')->get() as $c) {
            $mesPago = ComisionController::esTiendaTrimestral((int) $c->tienda_id) ? '2026-12' : '2026-10';
            $c->update([
                'mes_venta'        => '2026-10',
                'fecha_venta'      => '2026-10-31',
                'fecha_disponible' => ComisionController::diaDePagoDe($mesPago, (int) $c->tienda_id)->toDateString(),
            ]);
        }

        ComisionController::sincronizarValorOrden($orden->fresh());
        ComisionController::pedirRecalculo('migración #4423 a octubre');

        echo "[#4423] Ahora es del 31 de octubre.\n";
    }

    public function down(): void
    {
        // Es una corrección de datos: no se deshace.
    }
};
