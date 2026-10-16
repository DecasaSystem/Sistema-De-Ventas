<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los perfiles alternos dejan de vivir solo en el aparato.
 *
 * La cuenta ya recordaba CON QUIÉN alterna, pero la sesión de cada uno quedaba
 * en el localStorage del navegador: al entrar desde otro celular, o si el
 * navegador borraba los datos, había que volver a escribir la contraseña de
 * cada compañero. Ahora, cuando la otra persona escribe su contraseña una vez,
 * queda anotado que AUTORIZÓ turnarse la sesión con esta cuenta, y el servidor
 * le vuelve a abrir su sesión en cualquier aparato donde entre la principal.
 *
 * Solo agrega una columna. Los vínculos de antes quedan sin autorizar (cualquiera
 * podía anotar a quien quisiera sin su contraseña); el aparato que todavía tiene
 * la sesión de esa persona los autoriza solo la primera vez que abre la app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('perfiles_alternos', function (Blueprint $table) {
            $table->timestamp('autorizado_at')->nullable()->after('posicion');
        });
    }

    public function down(): void
    {
        Schema::table('perfiles_alternos', function (Blueprint $table) {
            $table->dropColumn('autorizado_at');
        });
    }
};
