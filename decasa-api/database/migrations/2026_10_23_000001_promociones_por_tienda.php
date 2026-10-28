<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promociones por tienda (dueño, 2026-10-15).
 *
 * El caso: la cama Bali de 1.40 va con 15 % menos en Unicentro y El Edén del
 * 1 al 31 de octubre. Hasta ahora había que bajarle el precio al producto y
 * acordarse de subirlo el 1.º de noviembre (y el precio bajaba en TODAS las
 * tiendas). Una promoción se programa una vez: qué se rebaja, en qué tiendas,
 * cuánto y de qué fecha a qué fecha. El precio de lista no se toca.
 *
 * Qué se rebaja, de lo más general a lo más concreto:
 *   - el producto entero (config_id y variante_id vacíos): todas sus versiones;
 *   - una opción del producto (config_id): la medida 1.40, "con baúl"…;
 *   - una tela/medida de las de `producto_variantes` (variante_id).
 * Si al mismo producto le aplican dos, gana la más concreta (ver
 * promoParaItem en utils/promociones.js de la app). Desde 2026-10-28 lo que
 * se rebaja va en `promocion_productos` (migración promociones_varios_productos).
 *
 * `orden_items.promocion_id` deja escrito con qué promoción se vendió: el
 * precio que se guarda ya viene rebajado, igual que con el descuento por
 * producto, así que esto es para saberlo, no para volver a calcularlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('promociones')) {
            Schema::create('promociones', function (Blueprint $table) {
                $table->id();
                // Opcional: "Feria de octubre". Sin nombre se muestra el producto.
                $table->string('nombre', 120)->nullable();
                $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
                // Si se borra la opción o la tela, la promoción se va con ella:
                // quedarse sin ellas la volvería de todo el producto sin avisar.
                $table->foreignId('config_id')->nullable()->constrained('producto_variante_configs')->cascadeOnDelete();
                $table->foreignId('variante_id')->nullable()->constrained('producto_variantes')->cascadeOnDelete();
                // porcentaje → valor = 15 (15 % menos)
                // monto      → valor = 200000 (se le quitan $200.000)
                // precio     → valor = 1290000 (queda en $1.290.000)
                $table->string('tipo', 20);
                $table->decimal('valor', 15, 2);
                // Días completos en hora de Bogotá, los dos incluidos.
                $table->date('fecha_inicio');
                $table->date('fecha_fin');
                // Apagarla antes de tiempo sin borrarla (se vendió con ella).
                $table->boolean('activa')->default(true);
                $table->foreignId('creado_por_id')->nullable()->constrained('usuarios')->nullOnDelete();
                $table->timestamps();

                $table->index(['producto_id', 'fecha_inicio', 'fecha_fin']);
            });
        }

        if (! Schema::hasTable('promocion_tienda')) {
            Schema::create('promocion_tienda', function (Blueprint $table) {
                $table->id();
                $table->foreignId('promocion_id')->constrained('promociones')->cascadeOnDelete();
                $table->foreignId('tienda_id')->constrained('tiendas')->cascadeOnDelete();
                $table->unique(['promocion_id', 'tienda_id']);
            });
        }

        Schema::table('orden_items', function (Blueprint $table) {
            if (! Schema::hasColumn('orden_items', 'promocion_id')) {
                $table->foreignId('promocion_id')->nullable()->constrained('promociones')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orden_items', function (Blueprint $table) {
            if (Schema::hasColumn('orden_items', 'promocion_id')) {
                $table->dropConstrainedForeignId('promocion_id');
            }
        });
        Schema::dropIfExists('promocion_tienda');
        Schema::dropIfExists('promociones');
    }
};
