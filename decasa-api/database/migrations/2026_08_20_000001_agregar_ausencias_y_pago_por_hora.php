<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dos cosas nuevas, relacionadas:
 *
 * 1. Un sueldo (del catálogo o personalizado) ya no se define solo "por
 *    día": puede ser por día o por hora, y las horas que dura un día
 *    completo se configuran ahí mismo — no hay una jornada estándar única
 *    para todos, hay gente que trabaja más, gente que trabaja menos, y
 *    gente que cobra por hora sin contrato fijo (taller u oficina).
 *
 * 2. `nomina_ausencias`: una falta con fecha real (y horas, para faltas
 *    parciales). Se registra contra el empleado y una fecha, no contra un
 *    item de período, porque la fecha puede caer en una quincena que
 *    todavía no existe — queda pendiente y se vincula sola cuando ese
 *    período se crea.
 *
 * No hay ningún sueldo, empleado con valor, período ni item real cargado
 * todavía (confirmado contra la base: 0 filas en las 4 tablas de nómina),
 * así que renombrar `valor_dia` no tiene datos que traducir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nomina_sueldos', function (Blueprint $table) {
            $table->renameColumn('valor_dia', 'valor');
        });
        Schema::table('nomina_sueldos', function (Blueprint $table) {
            $table->enum('unidad', ['dia', 'hora'])->default('dia')->after('valor');
            $table->decimal('horas_dia', 4, 2)->default(8)->after('unidad');
        });

        Schema::table('empleados', function (Blueprint $table) {
            $table->renameColumn('valor_dia', 'valor');
        });
        Schema::table('empleados', function (Blueprint $table) {
            $table->enum('unidad', ['dia', 'hora'])->default('dia')->after('valor');
            $table->decimal('horas_dia', 4, 2)->default(8)->after('unidad');
        });

        Schema::table('nomina_items', function (Blueprint $table) {
            // Congelado al sembrar el período, igual que valor_dia/valor_label:
            // un item viejo no se mueve si después cambian las horas del sueldo.
            $table->decimal('horas_dia', 4, 2)->default(8)->after('valor_dia');
        });

        Schema::create('nomina_ausencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empleado_id')->constrained('empleados')->cascadeOnDelete();
            // Null mientras la fecha no cae en ningun periodo creado todavia.
            $table->foreignId('nomina_item_id')->nullable()->constrained('nomina_items')->nullOnDelete();
            $table->date('fecha');
            $table->decimal('horas', 4, 2);
            $table->string('motivo', 160)->nullable();
            $table->timestamps();
            $table->unique(['empleado_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nomina_ausencias');

        Schema::table('nomina_items', function (Blueprint $table) {
            $table->dropColumn('horas_dia');
        });

        Schema::table('empleados', function (Blueprint $table) {
            $table->dropColumn(['unidad', 'horas_dia']);
        });
        Schema::table('empleados', function (Blueprint $table) {
            $table->renameColumn('valor', 'valor_dia');
        });

        Schema::table('nomina_sueldos', function (Blueprint $table) {
            $table->dropColumn(['unidad', 'horas_dia']);
        });
        Schema::table('nomina_sueldos', function (Blueprint $table) {
            $table->renameColumn('valor', 'valor_dia');
        });
    }
};
