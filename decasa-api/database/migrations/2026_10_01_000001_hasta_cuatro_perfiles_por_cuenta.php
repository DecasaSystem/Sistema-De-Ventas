<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Una cuenta alterna con hasta tres personas más, no con una sola.
 *
 * El doble perfil guardaba UN `perfil_alterno_id` en la fila del usuario, y
 * en un mostrador donde se turnan tres o cuatro personas eso obligaba a
 * cerrar sesión igual. La lista pasa a su propia tabla, con la posición en
 * que se agregó cada uno para que salgan siempre en el mismo orden.
 *
 * Lo ya configurado se conserva: el alterno que había queda de primero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perfiles_alternos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->foreignId('alterno_id')->constrained('usuarios')->cascadeOnDelete();
            $table->unsignedTinyInteger('posicion')->default(0);
            $table->timestamps();
            $table->unique(['usuario_id', 'alterno_id']);
        });

        DB::table('usuarios')->whereNotNull('perfil_alterno_id')
            ->select('id', 'perfil_alterno_id')->orderBy('id')->get()
            ->each(fn ($u) => DB::table('perfiles_alternos')->insert([
                'usuario_id' => $u->id, 'alterno_id' => $u->perfil_alterno_id, 'posicion' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]));

        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropForeign(['perfil_alterno_id']);
            $table->dropColumn('perfil_alterno_id');
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->foreignId('perfil_alterno_id')->nullable()->after('tienda_default_id')
                ->constrained('usuarios')->nullOnDelete();
        });

        DB::table('perfiles_alternos')->where('posicion', 0)->get()
            ->each(fn ($f) => DB::table('usuarios')->where('id', $f->usuario_id)->update(['perfil_alterno_id' => $f->alterno_id]));

        Schema::dropIfExists('perfiles_alternos');
    }
};
