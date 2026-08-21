<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién hizo cada paso, cuánto se demoró y qué tan bien quedó.
 *
 * Hasta ahora `produccion_pasos.trabajadores` era un JSON de nombres escritos
 * a mano: "Jhon", "jhon", "Jhon P." eran tres personas distintas para el
 * sistema, así que no había forma de saber cuánto trabajo lleva alguien ni de
 * calificarlo. Ahora cada participación es una fila que apunta al trabajador
 * de verdad, y encima cuelgan las horas y la calidad.
 *
 * La columna JSON vieja NO se borra: es el registro de lo que se escribió en
 * los pasos ya cerrados, y esos nombres no se pueden convertir en usuarios sin
 * adivinar a quién se referían.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paso_trabajadores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paso_id')->constrained('produccion_pasos')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('usuarios');

            // Se puede asignar al empezar el paso y también al cerrarlo, por si
            // al encargado se le olvidó ponerlo antes.
            $table->foreignId('asignado_por')->nullable()->constrained('usuarios');
            $table->timestamp('asignado_at')->useCurrent();

            // Quedan en null mientras el paso está en proceso: sólo al terminar
            // se sabe cuánto tomó y cómo quedó.
            $table->decimal('horas', 6, 2)->nullable();
            $table->unsignedTinyInteger('calidad')->nullable();   // 1 a 5 estrellas
            $table->string('comentario', 300)->nullable();
            $table->foreignId('calificado_por')->nullable()->constrained('usuarios');
            $table->timestamp('calificado_at')->nullable();

            $table->timestamps();

            // Una persona no participa dos veces en el mismo paso.
            $table->unique(['paso_id', 'usuario_id']);
            // El perfil del trabajador se arma leyendo por usuario.
            $table->index(['usuario_id', 'calidad']);
        });

        Schema::table('produccion_pasos', function (Blueprint $table) {
            // Sin esto no hay contra qué medir las horas ni cuánto lleva
            // esperando un paso que nadie ha tocado.
            $table->timestamp('iniciado_at')->nullable()->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('produccion_pasos', function (Blueprint $table) {
            $table->dropColumn('iniciado_at');
        });
        Schema::dropIfExists('paso_trabajadores');
    }
};
