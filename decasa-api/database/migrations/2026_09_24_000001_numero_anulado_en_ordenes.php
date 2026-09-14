<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anular una orden y correr las siguientes.
 *
 * Al cancelar una orden se puede dejar su consecutivo quemado (queda el
 * hueco, como una factura anulada en el talonario) o soltarlo y correr las
 * órdenes posteriores un número hacia abajo para que no quede hueco. En el
 * segundo caso la orden se queda sin número, y hay que seguir sabiendo cuál
 * tenía: es lo que dice el papel que se le dio al cliente y lo que la gente
 * busca. Se guarda aquí la referencia que tenía ("#4290", "R-1103").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $table->string('numero_anulado', 30)->nullable()->after('grupo_secuencia');
        });
    }

    public function down(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $table->dropColumn('numero_anulado');
        });
    }
};
