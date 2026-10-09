<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Varias fotos en un mismo mensaje del chat de la orden.
 *
 * Hasta ahora cabía una: para mostrar la tela, la veta y el golpe había que
 * mandar tres mensajes (y tres avisos a cada quien). `imagenes` guarda la
 * lista; `imagen_url` se sigue llenando con la primera para todo lo que ya
 * la lee (las garantías y devoluciones también escriben en este chat).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orden_mensajes', function (Blueprint $table) {
            if (! Schema::hasColumn('orden_mensajes', 'imagenes')) {
                $table->json('imagenes')->nullable()->after('imagen_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orden_mensajes', function (Blueprint $table) {
            if (Schema::hasColumn('orden_mensajes', 'imagenes')) {
                $table->dropColumn('imagenes');
            }
        });
    }
};
