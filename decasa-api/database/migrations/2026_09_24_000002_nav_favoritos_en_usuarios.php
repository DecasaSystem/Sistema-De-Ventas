<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada quien elige qué módulos van en la barra de abajo.
 *
 * La barra muestra cuatro accesos fijos y el resto detrás de "Más", y el
 * orden lo decidía el programa por rol. A un vendedor que vive en Traslado o
 * en Estadísticas le tocaba abrir "Más" cada vez. Se guarda en el usuario
 * —no en el aparato— para que la barra sea la misma en el celular y en el
 * computador. Es la lista de nombres de ruta, en el orden elegido; null =
 * la de siempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->json('nav_favoritos')->nullable()->after('firma_url');
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn('nav_favoritos');
        });
    }
};
