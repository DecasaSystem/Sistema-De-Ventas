<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La orden a medio hacer deja de vivir solo en el aparato.
 *
 * Nueva orden ya se guardaba sola en el teléfono (IndexedDB) para no perderla
 * si Android cerraba la app. Pero cada aparato tenía su copia y no se hablaban:
 * se recargaba en el celular, se seguía en el computador y el computador
 * ofrecía SU copia vieja (productos que faltaban, otro paso). Y al crear la
 * orden en uno, el otro la seguía ofreciendo: se podía crear dos veces.
 *
 * Una fila por persona y formulario (`clave`, la misma del respaldo local).
 * `revision` sube en cada guardado y al borrarla: un aparato que guarda sobre
 * una revisión que no es la última se entera de que la orden siguió (o se
 * terminó) en otro. Al borrarla la fila queda con `eliminado_at` para que los
 * demás aparatos sepan que ya no hay que ofrecerla.
 *
 * Solo crea una tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('borradores_en_curso', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->string('clave', 150);
            $table->unsignedInteger('revision')->default(0);
            $table->longText('datos')->nullable();
            $table->text('resumen')->nullable();
            $table->string('aparato_id', 64)->nullable();
            $table->string('aparato', 40)->nullable();
            $table->timestamp('guardado_at')->nullable();
            $table->timestamp('eliminado_at')->nullable();
            $table->timestamps();

            $table->unique(['usuario_id', 'clave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('borradores_en_curso');
    }
};
