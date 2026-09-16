<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Que el inventario de telas se mueva solo con las ventas.
 *
 * Hoy los metros de cada tela se recargan y se descuentan a mano. La idea es
 * que a cada producto tapizado se le diga cuánta tela lleva, y que al vender
 * uno para fabricar (o personalizar, o retapizar) esos metros queden
 * apartados de la tela elegida y se descuenten cuando el taller lo termine.
 *
 * Dos tablas:
 *
 * - `producto_consumo_telas`: los metros por unidad de cada producto. Una
 *   fila sin medida es el consumo base; una fila por medida
 *   (`producto_variante_configs`) manda sobre la base cuando el ítem trae esa
 *   medida. El color no va: un sofá gasta lo mismo en gris que en azul.
 *
 * - `tela_reservas`: qué apartó cada ítem de orden y en qué quedó. Es lo que
 *   permite soltar o descontar exactamente lo que se apartó, aunque después
 *   alguien cambie los metros del producto.
 *
 * Todo nace apagado (`telas_consumo_activo` = 0): se enciende desde Telas
 * cuando los consumos estén cargados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('producto_consumo_telas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->foreignId('config_id')->nullable()->constrained('producto_variante_configs')->cascadeOnDelete();
            $table->decimal('metros', 8, 2);
            $table->timestamps();

            $table->index(['producto_id', 'config_id']);
        });

        Schema::create('tela_reservas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_item_id')->constrained('orden_items')->cascadeOnDelete();
            $table->foreignId('catalogo_tela_id')->constrained('catalogo_telas')->cascadeOnDelete();
            $table->decimal('metros', 8, 2);
            // reservada → consumida (el taller terminó) | liberada (se canceló).
            $table->string('estado', 20)->default('reservada');
            $table->string('detalle', 200)->nullable();
            $table->timestamps();

            $table->index(['orden_item_id', 'estado']);
            $table->index(['catalogo_tela_id', 'estado']);
        });

        DB::table('configuracion')->updateOrInsert(
            ['clave' => 'telas_consumo_activo'],
            ['valor' => '0'],
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('tela_reservas');
        Schema::dropIfExists('producto_consumo_telas');
        DB::table('configuracion')->where('clave', 'telas_consumo_activo')->delete();
    }
};
