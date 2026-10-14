<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recibo de entrega y pago.
 *
 * El acta de satisfacción que firma quien recibe ahora también es el recibo
 * de lo que se pagó en la puerta: el cliente firma una sola vez. Para el
 * efectivo es el único soporte (la transferencia tiene su pantallazo y la
 * tarjeta el voucher del datáfono), así que lleva consecutivo propio (RC-…)
 * y un enlace para mandárselo al cliente por WhatsApp o correo.
 *
 * Solo agrega columnas y la fila del contador: nada de lo de antes cambia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('despacho_items', function (Blueprint $table) {
            if (! Schema::hasColumn('despacho_items', 'recibo_numero')) {
                $table->unsignedInteger('recibo_numero')->nullable()->unique();
            }
            if (! Schema::hasColumn('despacho_items', 'recibo_token')) {
                $table->string('recibo_token', 64)->nullable()->unique();
            }
            if (! Schema::hasColumn('despacho_items', 'recibo_enviado_at')) {
                $table->timestamp('recibo_enviado_at')->nullable();
            }
            if (! Schema::hasColumn('despacho_items', 'recibo_enviado_a')) {
                $table->string('recibo_enviado_a', 150)->nullable();
            }
        });

        if (Schema::hasTable('orden_secuencias')
            && ! DB::table('orden_secuencias')->where('grupo', 'recibos_entrega')->exists()) {
            DB::table('orden_secuencias')->insert(['grupo' => 'recibos_entrega', 'ultimo_numero' => 0]);
        }
    }

    public function down(): void
    {
        Schema::table('despacho_items', function (Blueprint $table) {
            foreach (['recibo_numero', 'recibo_token'] as $col) {
                if (Schema::hasColumn('despacho_items', $col)) {
                    $table->dropUnique("despacho_items_{$col}_unique");
                }
            }
        });
        Schema::table('despacho_items', function (Blueprint $table) {
            foreach (['recibo_numero', 'recibo_token', 'recibo_enviado_at', 'recibo_enviado_a'] as $col) {
                if (Schema::hasColumn('despacho_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
