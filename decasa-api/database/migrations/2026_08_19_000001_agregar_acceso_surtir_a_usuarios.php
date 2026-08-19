<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El módulo de Surtir estaba atado al rol: solo vendedor y supervisor podían
 * entrar, sin importar lo que necesitara cada tienda. Se vuelve una bandera
 * asignable, como acceso_redes o recarga_telas, para dársela a cualquiera
 * (un costurero, un ebanista) sin tener que cambiarle el rol.
 *
 * Los vendedores ya lo tenían por su rol, así que se les deja encendido de
 * una vez: nadie pierde acceso el día que esto se despliegue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->boolean('acceso_surtir')->default(false)->after('recarga_telas');
        });

        DB::table('usuarios')->where('rol', 'vendedor')->update(['acceso_surtir' => true]);
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn('acceso_surtir');
        });
    }
};
