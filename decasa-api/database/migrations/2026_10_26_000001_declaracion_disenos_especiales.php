<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La declaración para la fabricación de muebles con diseños y medidas
 * especiales (dueño, 2026-10-21).
 *
 * Es el papel que el cliente firmaba aparte cuando pedía algo que no está en
 * el catálogo: "suministré las medidas y el tamaño… y por ende los exonero de
 * cualquier responsabilidad en cuanto a las mismas". Ahora el vendedor escribe
 * en la orden cómo va el mueble (la ficha), el cliente la ve con los bocetos,
 * la aprueba producto por producto y la firma junto con el anexo de garantías
 * (una sola firma, dos documentos).
 *
 * Solo agrega columnas:
 *  - orden_items.ficha_especial: lo que el cliente aprueba de ese mueble
 *    (tipo, madera, tela, relleno, pintura, medidas, detalles).
 *  - anexos_garantia: qué versión del texto de la declaración se firmó, la foto
 *    exacta de los productos que el cliente aprobó (aunque después cambie la
 *    orden, el documento firmado dice lo que vio), su huella, y el cambio que
 *    el cliente pidió desde el enlace en vez de firmar.
 *  - ordenes.declaracion_foto_url: la foto de la declaración firmada en papel,
 *    igual que la del anexo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orden_items') && ! Schema::hasColumn('orden_items', 'ficha_especial')) {
            Schema::table('orden_items', function (Blueprint $t) {
                $t->json('ficha_especial')->nullable();
            });
        }

        if (Schema::hasTable('ordenes') && ! Schema::hasColumn('ordenes', 'declaracion_foto_url')) {
            Schema::table('ordenes', function (Blueprint $t) {
                $t->string('declaracion_foto_url', 500)->nullable();
            });
        }

        if (Schema::hasTable('anexos_garantia') && ! Schema::hasColumn('anexos_garantia', 'especiales')) {
            Schema::table('anexos_garantia', function (Blueprint $t) {
                $t->string('declaracion_version', 20)->nullable();
                $t->json('especiales')->nullable();
                $t->string('especiales_hash', 64)->nullable();
                $t->text('cambio_pedido')->nullable();
                $t->timestamp('cambio_pedido_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('anexos_garantia', 'especiales')) {
            Schema::table('anexos_garantia', function (Blueprint $t) {
                $t->dropColumn(['declaracion_version', 'especiales', 'especiales_hash', 'cambio_pedido', 'cambio_pedido_at']);
            });
        }
        if (Schema::hasColumn('ordenes', 'declaracion_foto_url')) {
            Schema::table('ordenes', fn (Blueprint $t) => $t->dropColumn('declaracion_foto_url'));
        }
        if (Schema::hasColumn('orden_items', 'ficha_especial')) {
            Schema::table('orden_items', fn (Blueprint $t) => $t->dropColumn('ficha_especial'));
        }
    }
};
