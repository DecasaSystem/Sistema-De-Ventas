<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Poder asignarle un proceso del taller a UNA PERSONA, no solo a una
 * especialidad.
 *
 * Hasta ahora quién trabajaba un paso salía únicamente de
 * `tipos_proceso.perfiles` cruzado con la especialidad del trabajador. Con
 * una o dos personas por especialidad eso es demasiado grueso: si Admin y
 * Mónica son las dos "Tapicero", no hay forma de que Pintura le llegue solo
 * a Mónica sin inventarle una especialidad propia.
 *
 * Esta tabla es la asignación directa, y SE SUMA a la de especialidad — no
 * la reemplaza. Un proceso puede tener especialidades, personas, o las dos:
 * quien trabaja el paso es la unión de ambas. Lo que ya está configurado
 * sigue funcionando igual sin migrar nada.
 *
 * Se borra sola si se borra el proceso o el trabajador, para no dejar
 * asignaciones apuntando a alguien que ya no existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proceso_trabajadores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tipo_proceso_id')->constrained('tipos_proceso')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->timestamps();
            // La misma persona no se asigna dos veces al mismo proceso.
            $table->unique(['tipo_proceso_id', 'usuario_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proceso_trabajadores');
    }
};
