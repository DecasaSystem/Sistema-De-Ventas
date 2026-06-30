<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LimpiarOrdenes extends Command
{
    protected $signature   = 'limpiar:ordenes';
    protected $description = 'Elimina todas las órdenes y datos derivados. Mantiene clientes, telas, trabajadores e inventario base.';

    public function handle(): int
    {
        $this->warn('⚠  Esta acción eliminará TODAS las órdenes y no puede deshacerse.');

        if (! $this->confirm('¿Confirmas que quieres borrar todas las órdenes?')) {
            $this->info('Cancelado.');
            return 0;
        }

        $this->info('Limpiando...');

        DB::transaction(function () {

            // Desactivar FK checks para poder truncar en cualquier orden
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            // 1. Producción
            DB::table('produccion_pasos')->truncate();
            $this->line('  ✓ produccion_pasos');

            DB::table('produccion')->truncate();
            $this->line('  ✓ produccion');

            // 2. Despachos
            DB::table('despacho_items')->truncate();
            $this->line('  ✓ despacho_items');

            DB::table('despachos')->truncate();
            $this->line('  ✓ despachos');

            // 3. Pagos y ediciones
            DB::table('pagos')->truncate();
            $this->line('  ✓ pagos');

            DB::table('orden_ediciones')->truncate();
            $this->line('  ✓ orden_ediciones');

            // 4. Ítems y órdenes
            DB::table('orden_items')->truncate();
            $this->line('  ✓ orden_items');

            DB::table('ordenes')->truncate();
            $this->line('  ✓ ordenes');

            // Reactivar FK checks
            DB::statement('SET FOREIGN_KEY_CHECKS=1');

            // 5. Liberar reservas de inventario
            DB::table('inventario')->update(['cantidad_reservada' => 0]);
            $this->line('  ✓ inventario.cantidad_reservada → 0');

            DB::table('inventario_variantes')->update(['cantidad_reservada' => 0]);
            $this->line('  ✓ inventario_variantes.cantidad_reservada → 0');

            // 6. Borrar movimientos de tipo reserva/liberacion (generados por órdenes)
            $mov = DB::table('inventario_movimientos')
                ->whereIn('tipo', ['reserva', 'liberacion'])
                ->delete();
            $this->line("  ✓ inventario_movimientos (reserva/liberacion): {$mov} eliminados");

            // 7. Borrar notificaciones relacionadas a órdenes
            $notif = DB::table('notificaciones')
                ->whereIn('tipo', [
                    'venta_nueva',
                    'asignar_fecha',
                    'paso_produccion',
                    'abono_registrado',
                    'fecha_asignada',
                    'orden_lista_entrega',
                ])
                ->delete();
            $this->line("  ✓ notificaciones de órdenes: {$notif} eliminadas");
        });

        $this->newLine();
        $this->info('✅ Limpieza completada. Clientes, telas, productos, trabajadores e historial de inventario intactos.');

        return 0;
    }
}
