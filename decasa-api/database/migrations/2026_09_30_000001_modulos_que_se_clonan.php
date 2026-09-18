<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un módulo se puede crear a partir de otro.
 *
 * Una mueblería lleva Telas por metros; la misma mueblería lleva Espumas, y
 * una de ropa lleva Hilos, y una de metal lleva Láminas. Es la misma pantalla
 * —marca, tipo, color, foto, recargar, descontar— con otro nombre, otro icono
 * y otra unidad. Hasta ahora eso era escribir una pantalla nueva por cada uno.
 *
 * Ahora un módulo puede decir de qué `plantilla` nació: el código busca la
 * pantalla por la plantilla y el nombre, el icono y la `config` (unidad,
 * singular, decimales) son de la empresa. Los módulos de siempre no tienen
 * plantilla y siguen exactamente igual.
 *
 * Los ítems de esos módulos nuevos viven en `modulo_items`, aparte de
 * `catalogo_telas`: las telas están amarradas a las órdenes, al consumo por
 * producto y a la producción, y una espuma no tiene por qué heredar nada de
 * eso. Cada ítem sabe de qué módulo es.
 *
 * `icono` pasa a texto porque ahora se puede dibujar: un dibujo se guarda
 * como el trazo de un SVG, que no cabe en sesenta letras.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modulos', function (Blueprint $table) {
            $table->string('plantilla', 30)->nullable()->after('clave');
            $table->json('config')->nullable()->after('icono');
            $table->text('icono')->change();
        });

        Schema::table('herramientas', function (Blueprint $table) {
            $table->text('icono')->nullable()->change();
        });

        Schema::create('modulo_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modulo_id')->constrained('modulos')->cascadeOnDelete();
            $table->string('marca', 100);
            $table->string('tipo', 100);
            $table->string('color', 100);
            $table->string('referencia', 200)->nullable();
            $table->string('textura', 100)->nullable();
            $table->string('foto_url', 500)->nullable();
            $table->decimal('cantidad_disponible', 10, 2)->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['modulo_id', 'marca', 'tipo', 'color']);
            $table->index(['modulo_id', 'marca']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modulo_items');

        Schema::table('herramientas', function (Blueprint $table) {
            $table->string('icono', 60)->nullable()->change();
        });

        Schema::table('modulos', function (Blueprint $table) {
            $table->string('icono', 60)->change();
            $table->dropColumn(['plantilla', 'config']);
        });
    }
};
