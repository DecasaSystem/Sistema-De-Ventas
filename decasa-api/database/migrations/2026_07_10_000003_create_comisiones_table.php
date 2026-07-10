<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comisiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_id')->unique()->constrained('ordenes');
            $table->foreignId('vendedor_id')->constrained('usuarios');
            $table->foreignId('tienda_id')->constrained('tiendas');
            $table->char('mes_venta', 7);        // 'YYYY-MM'
            $table->decimal('valor_orden', 15, 2);
            $table->date('fecha_venta');
            $table->date('fecha_disponible');    // fecha_venta + 1 mes
            $table->enum('estado', ['pendiente', 'lista', 'pagada'])->default('pendiente');
            $table->decimal('monto_comision', 15, 2)->nullable();
            $table->timestamp('fecha_pago')->nullable();
            $table->foreignId('pagada_por')->nullable()->constrained('usuarios');
            $table->boolean('notificado_lista')->default(false);
            $table->timestamps();

            $table->index(['vendedor_id', 'mes_venta']);
            $table->index('estado');
        });

        // Seed comisiones para órdenes confirmadas ya existentes
        DB::statement("
            INSERT IGNORE INTO comisiones
                (orden_id, vendedor_id, tienda_id, mes_venta, valor_orden,
                 fecha_venta, fecha_disponible, estado, created_at, updated_at)
            SELECT
                o.id,
                o.vendedor_id,
                o.tienda_id,
                DATE_FORMAT(o.created_at, '%Y-%m'),
                o.valor_total,
                DATE(o.created_at),
                DATE_ADD(DATE(o.created_at), INTERVAL 1 MONTH),
                'pendiente',
                NOW(),
                NOW()
            FROM ordenes o
            WHERE o.estado != 'borrador'
              AND o.vendedor_id IS NOT NULL
              AND o.tienda_id IS NOT NULL
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('comisiones');
    }
};
