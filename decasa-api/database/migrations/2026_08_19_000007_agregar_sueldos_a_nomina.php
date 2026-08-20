<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La unidad real de un sueldo es el valor por día: de ahí se sabe solo la
 * quincena (×15) y el mes (×30), y una falta se resta exacta (días × valor
 * día) sin tener que pensar en fracciones del período.
 *
 * `nomina_sueldos` es el catálogo con nombre ("Mínimo" = $X/día) para no
 * escribir el valor cada vez que se da de alta un trabajador. Un empleado
 * puede apuntar a uno de estos (`nomina_sueldo_id`) o tener su propio valor
 * personalizado (`valor_dia` directo) — ambos casos conviven.
 *
 * No hay períodos de nómina reales creados todavía (confirmado contra la
 * base: 0 períodos, 0 items) y los 32 empleados importados están en 0, así
 * que no hay datos que traducir — se puede reemplazar `valor_base` de una.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nomina_sueldos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 60);
            $table->decimal('valor_dia', 12, 2);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::table('empleados', function (Blueprint $table) {
            $table->foreignId('nomina_sueldo_id')->nullable()->after('cargo')->constrained('nomina_sueldos')->nullOnDelete();
            $table->decimal('valor_dia', 12, 2)->nullable()->after('nomina_sueldo_id');
            $table->dropColumn('valor_base');
        });

        // valor_label queda en null cuando el empleado usa un sueldo del
        // catálogo (el nombre sale de ahí) — antes era NOT NULL con default.
        DB::statement('ALTER TABLE empleados MODIFY valor_label VARCHAR(60) NULL DEFAULT NULL');

        Schema::table('nomina_items', function (Blueprint $table) {
            $table->renameColumn('valor_base', 'valor_dia');
        });
    }

    public function down(): void
    {
        Schema::table('nomina_items', function (Blueprint $table) {
            $table->renameColumn('valor_dia', 'valor_base');
        });

        DB::statement("ALTER TABLE empleados MODIFY valor_label VARCHAR(60) NOT NULL DEFAULT 'Salario quincenal'");

        Schema::table('empleados', function (Blueprint $table) {
            $table->dropConstrainedForeignId('nomina_sueldo_id');
            $table->dropColumn('valor_dia');
            $table->decimal('valor_base', 12, 2)->default(0);
        });

        Schema::dropIfExists('nomina_sueldos');
    }
};
