<?php

use App\Models\CajaBitacora;
use App\Models\Orden;
use App\Models\OrdenEdicion;
use App\Models\OrdenMensaje;
use App\Models\Pago;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La #1275 (Jairo Arcila, Unicentro Pereira) pagó su anticipo de $1.000.000
 * por TRANSFERENCIA el 8 de octubre. El 19 de noviembre se canceló la silla y
 * el cliente dejó esa plata para la #1316; como no hay forma de pasar un saldo
 * a favor de una orden a otra, se registró con "Devolver plata" y en EFECTIVO.
 * La caja de Unicentro bajó $1.000.000 que nunca salieron del cajón (se veía
 * como "Venta efectivo #1275 +$-1.000.000"). En la #1316 el millón entró como
 * abono por transferencia el 23 de noviembre. Revisado en producción (solo
 * lectura) y pedido del usuario el 2026-11-23: corregirla.
 *
 * Solo cambia el medio de esa devolución a "otro" (no es efectivo: no toca la
 * caja). El monto no cambia: la plata sí salió de la #1275, hacia la #1316. Así
 * no se mueve el saldo de las órdenes, ni la cartera, ni las bonificaciones; lo
 * único que cambia es la caja de Unicentro, que sube $1.000.000. El cambio deja
 * su renglón en la caja (CajaBitacora), en el historial y en el chat de la orden.
 *
 * Solo si la encuentra tal cual (ese pago, esa orden, en efectivo); correrla dos
 * veces no hace nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        $orden = Orden::where('numero_orden', 1275)->whereNull('serie')
            ->whereHas('cliente', fn ($q) => $q->where('nombre', 'like', '%jairo%')->where('nombre', 'like', '%arcila%'))
            ->first();

        $pago = $orden
            ? Pago::where('orden_id', $orden->id)->where('tipo', 'reembolso')
                ->where('metodo', 'efectivo')->where('monto', -1000000)->first()
            : null;

        if (! $pago) {
            echo "[#1275] No se encontró la devolución en efectivo: no se cambió nada.\n";
            return;
        }

        $motivo = 'No salió efectivo: el cliente pasó el $1.000.000 que tenía a favor a la #1316 (lo había pagado por transferencia).';

        DB::transaction(function () use ($orden, $pago, $motivo) {
            $pago->update([
                'metodo' => 'otro',
                'notas'  => trim(($pago->notas ? $pago->notas . ' · ' : '') . 'Corregido el 23/11/2026: ' . $motivo),
            ]);

            // El renglón de la caja lo anota Pago solo; se le pone el porqué.
            $bit = CajaBitacora::where('pago_id', $pago->id)->latest('id')->first();
            $bit?->update(['descripcion' => trim($bit->descripcion . ' ' . $motivo)]);

            $usuarioId = DB::table('usuarios')->where('rol', 'supervisor')->where('activo', true)->orderBy('id')->value('id')
                ?? $orden->vendedor_id;
            if ($usuarioId) {
                OrdenEdicion::create(['orden_id' => $orden->id, 'usuario_id' => $usuarioId, 'cambios' => [
                    ['campo' => "pago_{$pago->id}_metodo", 'label' => 'Devolución de plata — método',
                     'antes' => 'efectivo', 'despues' => 'otro'],
                ]]);
            }

            OrdenMensaje::create([
                'orden_id'   => $orden->id,
                'usuario_id' => $usuarioId,
                'mensaje'    => '🧾 Se corrigió la devolución de $1.000.000: no salió en efectivo de la caja. ' . $motivo,
            ]);
        });

        echo "[#1275] Devolución corregida a 'otro': la caja de Unicentro sube \$1.000.000.\n";
    }

    public function down(): void
    {
        // Es una corrección de datos: no se deshace sola.
    }
};
