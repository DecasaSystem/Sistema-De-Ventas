<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Producir contra stock: una producción que no nace de una venta.
 *
 * Hasta ahora `produccion` siempre colgaba de un `orden_item`. El taller
 * necesita fabricar unidades de un producto —o de uno nuevo— para dejarlas en
 * la Reserva de Fábrica, sin cliente, sin orden y sin despacho. Se reutiliza
 * el mismo flujo de pasos; lo único que cambia es de dónde viene la pieza
 * (`destino`) y a dónde va al terminar (al inventario de fábrica, estado
 * `en_reserva`, en vez de a `listo` contra la orden).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produccion', function (Blueprint $table) {
            // Producir para stock no viene de una venta. El índice unique tolera
            // varios NULL, así que las de reserva conviven sin chocar.
            $table->unsignedBigInteger('orden_item_id')->nullable()->change();

            // 'orden' = comportamiento de siempre; 'reserva' = fabricar para la
            // Reserva de Fábrica.
            $table->enum('destino', ['orden', 'reserva'])->default('orden')->after('orden_item_id');

            // Qué se produce cuando no hay orden_item.
            $table->foreignId('producto_id')->nullable()->after('destino')->constrained('productos');
            $table->foreignId('variante_id')->nullable()->after('producto_id')
                  ->constrained('producto_variantes')->nullOnDelete();
            $table->foreignId('combo_config_id')->nullable()->after('variante_id')
                  ->constrained('producto_variante_configs')->nullOnDelete();
            $table->string('variante_detalle', 200)->nullable()->after('combo_config_id');
            $table->unsignedInteger('cantidad')->default(1)->after('variante_detalle');

            // Medidas / acabados por categoría + notas, igual que
            // orden_items.specs_personalizacion.
            $table->json('specs')->nullable()->after('cantidad');

            $table->foreignId('creado_por')->nullable()->after('specs')->constrained('usuarios');

            // Cuándo entraron las unidades a la Reserva.
            $table->timestamp('depositado_at')->nullable()->after('creado_por');
        });

        // Estado nuevo: producción terminada y ya depositada en fábrica.
        DB::statement("ALTER TABLE produccion MODIFY COLUMN estado
            ENUM('pendiente','en_proceso','listo','retrasado','entregado','cancelado','pendiente_despachador','en_reserva')
            NOT NULL DEFAULT 'pendiente'");
    }

    public function down(): void
    {
        // Si hay producciones de reserva sin depositar, sus pasos quedarían
        // huérfanos: se cancelan antes de tumbar las columnas.
        DB::table('produccion')->where('destino', 'reserva')->update(['estado' => 'entregado']);

        DB::statement("ALTER TABLE produccion MODIFY COLUMN estado
            ENUM('pendiente','en_proceso','listo','retrasado','entregado','cancelado','pendiente_despachador')
            NOT NULL DEFAULT 'pendiente'");

        Schema::table('produccion', function (Blueprint $table) {
            $table->dropConstrainedForeignId('producto_id');
            $table->dropConstrainedForeignId('variante_id');
            $table->dropConstrainedForeignId('combo_config_id');
            $table->dropConstrainedForeignId('creado_por');
            $table->dropColumn(['destino', 'variante_detalle', 'cantidad', 'specs', 'depositado_at']);
        });
    }
};
