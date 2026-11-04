<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Una promoción para varios productos (pedido del usuario, 2026-10-28): "que una
 * promoción pueda ser creada y reutilizable en varios productos, así si varios
 * tienen la misma promoción con las mismas reglas sea fácil y rápido de poner".
 * Y por cada producto se pregunta a qué versiones: a todas, solo a algunas
 * (la cama de 1.40 y la de 1.60, la de 2.00 no) o a ninguna (solo el producto
 * sin opciones).
 *
 * `promocion_productos`: una fila por cada cosa que se rebaja.
 *   - config_id y variante_id vacíos, sin_versiones = false → el producto con
 *     todas sus versiones;
 *   - config_id (una opción: medida, "con baúl"…) o variante_id (una tela) →
 *     solo esa versión; "solo algunas" son varias filas del mismo producto;
 *   - sin_versiones = true → solo cuando se vende sin ninguna opción ni tela.
 * Las reglas (cuánto, fechas, tiendas, encendida) siguen en `promociones`:
 * cambiarlas cambia todos sus productos de una.
 *
 * Las promociones que ya existen se pasan a la tabla nueva tal cual. Las
 * columnas viejas `promociones.producto_id/config_id/variante_id` se quedan
 * (regla 7: el código de antes las lee mientras arranca el nuevo); el código
 * nuevo solo les copia el primer producto. Pero ya no pueden borrar la
 * promoción entera en cascada: si se borra uno de 10 productos, se van solo
 * sus filas, no la promoción de los otros 9.
 *
 * Solo crea, copia y afloja llaves.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('promocion_productos')) {
            Schema::create('promocion_productos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('promocion_id')->constrained('promociones')->cascadeOnDelete();
                $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
                // Si se borra la opción o la tela, se va solo esa fila: dejarla
                // vacía la volvería "todas las versiones" sin avisar.
                $table->foreignId('config_id')->nullable()->constrained('producto_variante_configs')->cascadeOnDelete();
                $table->foreignId('variante_id')->nullable()->constrained('producto_variantes')->cascadeOnDelete();
                $table->boolean('sin_versiones')->default(false);

                $table->index('producto_id');
            });
        }

        // Las de antes, una fila cada una (si no se copiaron ya).
        $yaCopiadas = DB::table('promocion_productos')->pluck('promocion_id')->unique()->all();
        DB::table('promociones')->whereNotNull('producto_id')
            ->whereNotIn('id', $yaCopiadas ?: [0])
            ->orderBy('id')
            ->get(['id', 'producto_id', 'config_id', 'variante_id'])
            ->each(fn ($p) => DB::table('promocion_productos')->insert([
                'promocion_id'  => $p->id,
                'producto_id'   => $p->producto_id,
                'config_id'     => $p->config_id,
                'variante_id'   => $p->variante_id,
                'sin_versiones' => false,
            ]));

        // Las columnas viejas ya no borran la promoción entera.
        Schema::table('promociones', function (Blueprint $table) {
            $table->dropForeign(['producto_id']);
            $table->dropForeign(['config_id']);
            $table->dropForeign(['variante_id']);
        });
        Schema::table('promociones', function (Blueprint $table) {
            $table->unsignedBigInteger('producto_id')->nullable()->change();
            $table->foreign('producto_id')->references('id')->on('productos')->nullOnDelete();
            $table->foreign('config_id')->references('id')->on('producto_variante_configs')->nullOnDelete();
            $table->foreign('variante_id')->references('id')->on('producto_variantes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Las llaves vuelven a como estaban; producto_id se queda opcional
        // (volverlo obligatorio fallaría con una promoción sin productos).
        Schema::table('promociones', function (Blueprint $table) {
            $table->dropForeign(['producto_id']);
            $table->dropForeign(['config_id']);
            $table->dropForeign(['variante_id']);
        });
        Schema::table('promociones', function (Blueprint $table) {
            $table->foreign('producto_id')->references('id')->on('productos')->cascadeOnDelete();
            $table->foreign('config_id')->references('id')->on('producto_variante_configs')->cascadeOnDelete();
            $table->foreign('variante_id')->references('id')->on('producto_variantes')->cascadeOnDelete();
        });
        Schema::dropIfExists('promocion_productos');
    }
};
