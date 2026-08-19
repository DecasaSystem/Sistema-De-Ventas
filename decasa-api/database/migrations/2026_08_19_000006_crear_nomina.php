<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo de Nómina: simple, como se paga hoy a mano en Excel — nombre,
 * cédula, ocupación, un valor a pagar (ya no un texto fijo tipo "el
 * mínimo") y observaciones libres. No es un sistema de nómina legal/DIAN:
 * sin EPS, pensión, parafiscales ni retención — eso se decidió a propósito
 * con el usuario.
 *
 * `empleados` es una tabla aparte de `usuarios`: casi nadie del taller
 * (lijadores, costureras, lacadores...) tiene ni va a necesitar una cuenta
 * con clave para entrar a la app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empleados', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 120);
            $table->string('cedula', 20)->nullable()->unique();
            $table->string('cargo', 80)->nullable();
            $table->string('valor_label', 60)->default('Salario quincenal');
            $table->decimal('valor_base', 12, 2)->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('nomina_periodos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100);
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->unsignedInteger('dias_periodo');
            $table->timestamp('pagado_at')->nullable();
            $table->timestamps();
        });

        Schema::create('nomina_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nomina_periodo_id')->constrained('nomina_periodos')->cascadeOnDelete();
            $table->foreignId('empleado_id')->constrained('empleados');
            $table->string('valor_label', 60);
            $table->decimal('valor_base', 12, 2);
            // Admite medios días (14.5), por eso decimal y no entero.
            $table->decimal('dias_trabajados', 5, 2);
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });

        Schema::create('nomina_ajustes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nomina_item_id')->constrained('nomina_items')->cascadeOnDelete();
            $table->string('nombre', 120);
            // Con signo: positivo es bono, negativo es descuento manual.
            $table->decimal('monto', 12, 2);
            $table->timestamps();
        });

        Schema::table('usuarios', function (Blueprint $table) {
            $table->boolean('acceso_nomina')->default(false)->after('acceso_reserva');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('acceso_nomina')->default(false)->after('acceso_reserva');
        });

        // Los 32 trabajadores del Excel de ejemplo (NOMINA No 16-AGOSTO-31-2026).
        // Sin valor_base: no hay forma de saber a cuánto corresponde cada uno
        // hoy (el Excel solo decía "EL MINIMO" como texto) — se completa desde
        // la pantalla nueva, uno por uno.
        $ahora = now();
        $empleados = [
            ['nombre' => 'Alba Lucia Sanchez',          'cedula' => null,          'cargo' => 'Lijadora'],
            ['nombre' => 'Andrey Guevara',               'cedula' => '18418655',    'cargo' => 'Lijador'],
            ['nombre' => 'Daniel Buitrago',               'cedula' => '1094960484',  'cargo' => 'Lijador'],
            ['nombre' => 'Lorena',                        'cedula' => null,          'cargo' => 'Lijadora'],
            ['nombre' => 'Diego Salgado',                 'cedula' => '1014196792',  'cargo' => 'Pelador'],
            ['nombre' => 'Mauricio',                      'cedula' => null,          'cargo' => 'Lijador'],
            ['nombre' => 'Silvia',                        'cedula' => null,          'cargo' => 'Lijadora'],
            ['nombre' => 'Maria',                         'cedula' => null,          'cargo' => 'Lijadora'],
            ['nombre' => 'Adrian',                        'cedula' => null,          'cargo' => null],
            ['nombre' => 'Henry',                         'cedula' => null,          'cargo' => null],
            ['nombre' => 'Brayan',                        'cedula' => null,          'cargo' => 'Lijador'],
            ['nombre' => 'Darwin',                        'cedula' => null,          'cargo' => 'Lijador'],
            ['nombre' => 'Laura',                         'cedula' => null,          'cargo' => 'Costurera'],
            ['nombre' => 'Giovanny Ocampo',               'cedula' => '7551446',     'cargo' => 'Esqueletero'],
            ['nombre' => 'Anderson Monroy',               'cedula' => '80007775',    'cargo' => 'Ebanista'],
            ['nombre' => 'Antonio Tapasco',               'cedula' => '18493722',    'cargo' => 'Ebanista'],
            ['nombre' => 'Dorman Emilio Fuelantala',      'cedula' => null,          'cargo' => 'Ebanista'],
            ['nombre' => 'Edwin',                         'cedula' => null,          'cargo' => 'Ebanista'],
            ['nombre' => 'Jorge Teudulo',                 'cedula' => '9734406',     'cargo' => 'Ebanista'],
            ['nombre' => 'Omar',                          'cedula' => null,          'cargo' => 'Ebanista'],
            ['nombre' => 'Alexander Cardenas Taborda',    'cedula' => '7556692',     'cargo' => 'Tapicero'],
            ['nombre' => 'Javier Albarracin',             'cedula' => '7560398',     'cargo' => 'Tapicero'],
            ['nombre' => 'Javier Alfonso Colorado',       'cedula' => '89002851',    'cargo' => 'Tapicero'],
            ['nombre' => 'Misael Antonio Calle',          'cedula' => '16686080',    'cargo' => 'Tapicero'],
            ['nombre' => 'Miguel Reyes',                  'cedula' => null,          'cargo' => 'Tapicero'],
            ['nombre' => 'Mauricio Ladino',               'cedula' => '18419680',    'cargo' => 'Tapicero'],
            ['nombre' => 'Jenny Rivera',                  'cedula' => '1094881027',  'cargo' => 'Costurera'],
            ['nombre' => 'Lucero Ramirez',                'cedula' => '41921585',    'cargo' => 'Costurera'],
            ['nombre' => 'Arley Cardenas',                'cedula' => '9737245',     'cargo' => 'Costurero'],
            ['nombre' => 'Carlos Alberto Muñoz',          'cedula' => '9739978',     'cargo' => 'Pintor'],
            ['nombre' => 'Eduardo Perea',                 'cedula' => null,          'cargo' => 'Pintor'],
            ['nombre' => 'Andres',                        'cedula' => null,          'cargo' => 'Laquero'],
        ];
        foreach ($empleados as &$e) {
            $e['valor_label'] = 'Salario quincenal';
            $e['valor_base']  = 0;
            $e['activo']      = true;
            $e['created_at']  = $ahora;
            $e['updated_at']  = $ahora;
        }
        unset($e);
        DB::table('empleados')->insert($empleados);
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn('acceso_nomina');
        });
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('acceso_nomina');
        });
        Schema::dropIfExists('nomina_ajustes');
        Schema::dropIfExists('nomina_items');
        Schema::dropIfExists('nomina_periodos');
        Schema::dropIfExists('empleados');
    }
};
