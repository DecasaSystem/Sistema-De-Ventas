<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo se liquidó un trimestre.
 *
 * En Pereira y Circunvalar la comisión es trimestral y lo que sobra o falta
 * se arrastra al trimestre siguiente. Esa cadena se recalculaba entera cada
 * vez que alguien abría la pantalla, así que corregir el precio de una orden
 * vieja movía el déficit de un trimestre ya pagado — y con él, lo que se
 * debía en el siguiente.
 *
 * Con esta fecha, el trimestre que ya se liquidó queda quieto: se guarda lo
 * que se uso para pagar y eso es lo que rige, pase lo que pase después con
 * las órdenes de esos meses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tienda_trimestres', function (Blueprint $table) {
            $table->timestamp('cerrado_at')->nullable()->after('deficit_final');
        });
    }

    public function down(): void
    {
        Schema::table('tienda_trimestres', function (Blueprint $table) {
            $table->dropColumn('cerrado_at');
        });
    }
};
