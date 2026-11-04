<?php

use App\Http\Controllers\ComisionController;
use App\Models\Orden;
use App\Models\OrdenEdicion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dos restauraciones de Decasa Norte que eran de agosto se subieron al sistema
 * a finales de octubre (Paola), y quedaron con la fecha y el número del día en
 * que se subieron. Pedido del usuario, 2026-10-28:
 *
 * - R-1134 (CLAUDIA VALENCIA) es del 15 de agosto y su número es R-1090.
 * - R-1133 (ALBA LUCIA OVALLE M) es del 31 de agosto. Es la restauración de
 *   $6 M que en la conciliación de Norte (2026-10-23) faltaba en el sistema.
 *
 * La fecha de la venta es `created_at` (comisiones, reportes y metas la leen
 * de ahí, en hora de Bogotá): se pone a mediodía de ese día. Los pagos no se
 * mueven: el dinero entró cuando entró.
 *
 * El número: R-1090 estaba libre. La R-1134 era la última de la serie, así que
 * el contador vuelve a 1133 para no dejar el hueco; la próxima R vuelve a ser
 * la 1134. Si para cuando corra esto ya se creó otra R, el contador se deja.
 *
 * Las comisiones de las dos se rehacen con la fecha nueva (mes de venta,
 * equipo de ese día, fecha de pago) con la misma puerta que usa editar una
 * orden, y se pide el recálculo de todo para que el monto salga ya. Lo pagado
 * no se toca (ninguna de las dos tenía nada pagado al revisarlo en producción).
 *
 * Solo corrige esas dos órdenes y solo si las encuentra tal cual; correrla dos
 * veces no hace nada la segunda.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tocadas = [];

        // R-1134 → R-1090, 15 de agosto.
        $claudia = $this->buscar(1134, 'CLAUDIA VALENCIA');
        if ($claudia) {
            $ocupado = Orden::where('serie', 'R')->where('serie_numero', 1090)->exists();
            if ($ocupado) {
                echo "[R-1134] R-1090 ya existe: no se cambió nada.\n";
            } else {
                DB::transaction(function () use ($claudia) {
                    DB::table('ordenes')->where('id', $claudia->id)->update([
                        'serie_numero' => 1090,
                        'created_at'   => '2026-08-15 17:00:00', // 12:00 m. en Bogotá
                    ]);

                    // La 1134 era la última: el contador vuelve atrás para no dejar el hueco.
                    DB::table('orden_secuencias')->where('grupo', 'r')
                        ->where('ultimo_numero', 1134)->update(['ultimo_numero' => 1133]);
                });
                $this->historial($claudia, [
                    ['campo' => 'numeracion', 'label' => 'Número corregido', 'antes' => 'R-1134', 'despues' => 'R-1090'],
                    ['campo' => 'created_at', 'label' => 'Fecha de la venta', 'antes' => $this->dia($claudia), 'despues' => '15/08/2026'],
                ]);
                $tocadas[] = $claudia->id;
                echo "[R-1134] Ahora es R-1090, del 15 de agosto.\n";
            }
        }

        // R-1133, 31 de agosto.
        $alba = $this->buscar(1133, 'ALBA LUCIA OVALLE');
        if ($alba && ! str_starts_with((string) $alba->created_at, '2026-08-31')) {
            DB::table('ordenes')->where('id', $alba->id)->update([
                'created_at' => '2026-08-31 17:00:00', // 12:00 m. en Bogotá
            ]);
            $this->historial($alba, [
                ['campo' => 'created_at', 'label' => 'Fecha de la venta', 'antes' => $this->dia($alba), 'despues' => '31/08/2026'],
            ]);
            $tocadas[] = $alba->id;
            echo "[R-1133] Ahora es del 31 de agosto.\n";
        }

        foreach ($tocadas as $id) {
            ComisionController::sincronizarValorOrden(Orden::find($id));
        }
        if ($tocadas) {
            ComisionController::pedirRecalculo('migración restauraciones Norte agosto');
        }
    }

    /** La restauración de Norte con ese número y ese cliente, todavía con fecha de octubre. */
    private function buscar(int $numero, string $cliente): ?Orden
    {
        return Orden::where('serie', 'R')->where('serie_numero', $numero)
            ->where('created_at', '>=', '2026-10-01')
            ->whereHas('cliente', fn ($q) => $q->where('nombre', 'like', $cliente . '%'))
            ->whereHas('tienda', fn ($q) => $q->where('nombre', 'Decasa Norte'))
            ->first();
    }

    private function dia(Orden $orden): string
    {
        return $orden->created_at->copy()->setTimezone('America/Bogota')->format('d/m/Y');
    }

    /** Queda en el historial de la orden, a nombre de un supervisor (o de quien la subió). */
    private function historial(Orden $orden, array $cambios): void
    {
        $usuarioId = DB::table('usuarios')->where('rol', 'supervisor')->where('activo', true)->orderBy('id')->value('id')
            ?? $orden->vendedor_id;
        if (! $usuarioId) return;

        OrdenEdicion::create(['orden_id' => $orden->id, 'usuario_id' => $usuarioId, 'cambios' => $cambios]);
    }

    public function down(): void
    {
        // Es una corrección de datos: no se deshace.
    }
};
