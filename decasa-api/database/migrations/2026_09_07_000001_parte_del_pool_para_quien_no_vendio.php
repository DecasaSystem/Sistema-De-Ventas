<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La parte del pool de quien no vendió nada ese mes.
 *
 * En una tienda con meta, la comisión no es de la venta: es de la tienda. El
 * pool se parte entre los integrantes —en Norte siempre en 3— y a cada uno le
 * toca lo mismo, haya vendido diez muebles o ninguno.
 *
 * Pero acá toda comisión colgaba obligatoriamente de una orden, así que a quien
 * no vendía no le quedaba ni un renglón: el sistema calculaba bien su parte y
 * no tenía dónde escribirla, ni dónde marcarla como pagada. Su tercio no se lo
 * llevaba nadie.
 *
 * Con `orden_id` nulo cabe una fila que dice "la parte de agosto de Marta", que
 * es lo que de verdad se le paga.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comisiones', function (Blueprint $table) {
            // La FK se cae y se vuelve a poner: MySQL no deja cambiar a nulable
            // una columna con la restricción encima.
            $table->dropForeign(['orden_id']);
        });

        Schema::table('comisiones', function (Blueprint $table) {
            $table->unsignedBigInteger('orden_id')->nullable()->change();
        });

        Schema::table('comisiones', function (Blueprint $table) {
            $table->foreign('orden_id')->references('id')->on('ordenes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // Las filas sin orden no caben en el esquema viejo: se van.
        \Illuminate\Support\Facades\DB::table('comisiones')->whereNull('orden_id')->delete();

        Schema::table('comisiones', function (Blueprint $table) {
            $table->dropForeign(['orden_id']);
        });

        Schema::table('comisiones', function (Blueprint $table) {
            $table->unsignedBigInteger('orden_id')->nullable(false)->change();
        });

        Schema::table('comisiones', function (Blueprint $table) {
            $table->foreign('orden_id')->references('id')->on('ordenes')->cascadeOnDelete();
        });
    }
};
