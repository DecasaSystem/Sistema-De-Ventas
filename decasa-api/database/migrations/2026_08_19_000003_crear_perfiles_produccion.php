<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Antes, quién podía trabajar qué paso del taller salía de tres condiciones
 * fijas en código: rol=ebanista, rol=despachador, o rol=supervisor+es_tapicero
 * (ver TipoProceso::PERFILES). Eso significaba que solo yo podía agregar un
 * perfil nuevo o dárselo a alguien que no encajara en esos tres roles.
 *
 * perfiles_produccion es ese catálogo, ahora editable desde Gestión. Se
 * siembra con los mismos tres perfiles de hoy para que tipos_proceso.perfiles
 * (que ya guarda esas claves como texto) siga funcionando sin tocar sus datos.
 *
 * usuarios.perfil_produccion_id es la asignación: cualquier trabajador, de
 * cualquier rol, puede tener un perfil. Se respalda a quien ya lo tenía por
 * su rol, para que nadie pierda sus pasos asignados el día del despliegue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perfiles_produccion', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 40)->unique();
            $table->string('nombre', 60);
            $table->boolean('activo')->default(true);
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();
        });

        DB::table('perfiles_produccion')->insert([
            ['clave' => 'ebanista',    'nombre' => 'Ebanista',    'activo' => true, 'orden' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['clave' => 'tapicero',    'nombre' => 'Tapicero',    'activo' => true, 'orden' => 20, 'created_at' => now(), 'updated_at' => now()],
            ['clave' => 'despachador', 'nombre' => 'Despachador', 'activo' => true, 'orden' => 30, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::table('usuarios', function (Blueprint $table) {
            $table->foreignId('perfil_produccion_id')->nullable()->after('es_tapicero')
                ->constrained('perfiles_produccion')->nullOnDelete();
        });

        $idEbanista    = DB::table('perfiles_produccion')->where('clave', 'ebanista')->value('id');
        $idTapicero    = DB::table('perfiles_produccion')->where('clave', 'tapicero')->value('id');
        $idDespachador = DB::table('perfiles_produccion')->where('clave', 'despachador')->value('id');

        DB::table('usuarios')->where('rol', 'ebanista')->update(['perfil_produccion_id' => $idEbanista]);
        DB::table('usuarios')->where('rol', 'despachador')->update(['perfil_produccion_id' => $idDespachador]);
        DB::table('usuarios')->where('rol', 'supervisor')->where('es_tapicero', true)->update(['perfil_produccion_id' => $idTapicero]);
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropConstrainedForeignId('perfil_produccion_id');
        });
        Schema::dropIfExists('perfiles_produccion');
    }
};
