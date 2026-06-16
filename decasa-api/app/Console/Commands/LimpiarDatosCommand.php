<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LimpiarDatosCommand extends Command
{
    protected $signature   = 'decasa:limpiar-datos {--force : Saltar confirmación interactiva}';
    protected $description = 'Elimina clientes, órdenes, producción y cotizaciones. Conserva usuarios, productos, tiendas e inventario.';

    public function handle(): int
    {
        if (!$this->option('force')) {
            $this->warn('⚠  Esta acción eliminará PERMANENTEMENTE:');
            $this->line('   • Clientes');
            $this->line('   • Órdenes y sus ítems, pagos, ediciones');
            $this->line('   • Producción y pasos');
            $this->line('   • Cotizaciones y mensajes');
            $this->line('   • Despachos, traslados, surtidos');
            $this->line('   • Citas y conversaciones WhatsApp');
            $this->line('   • Notificaciones e historial de movimientos de inventario');
            $this->newLine();
            $this->line('   Se conservan: usuarios, productos, tiendas, inventario actual, configuración.');
            $this->newLine();

            if (!$this->confirm('¿Confirmas que deseas limpiar la base de datos?')) {
                $this->info('Operación cancelada.');
                return self::SUCCESS;
            }
        }

        $this->info('Limpiando base de datos...');

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        $tablas = [
            'notificaciones',
            'consulta_costo_mensajes',
            'consultas_costo',
            'produccion_pasos',
            'produccion',
            'despacho_items',
            'despachos',
            'pagos',
            'inventario_movimientos',
            'surtido_items',
            'surtido_tiendas',
            'surtidos',
            'traslados',
            'orden_ediciones',
            'orden_items',
            'ordenes',
            'citas',
            'conversaciones_wa',
            'clientes',
        ];

        foreach ($tablas as $tabla) {
            DB::table($tabla)->truncate();
            $this->line("  ✓ {$tabla}");
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->newLine();
        $this->info('Base de datos limpiada correctamente.');
        return self::SUCCESS;
    }
}
