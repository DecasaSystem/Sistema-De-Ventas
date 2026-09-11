<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un comprobante de pago puede ser varias fotos.
 *
 * Un anticipo se paga con dos transferencias, o el pantallazo no cabe en una
 * sola captura. La orden, cada pago y cada entrega guardaban UNA url, así que
 * la segunda foto no tenía dónde ir.
 *
 * Mismo patrón que `orden_items.boceto_fotos`: la lista completa va en JSON y
 * la columna vieja se sigue llenando con la primera, para que el PDF, los
 * reportes y el agente sigan leyendo lo de siempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $table->json('factura_fotos')->nullable()->after('factura_foto_url');
        });
        Schema::table('pagos', function (Blueprint $table) {
            $table->json('comprobante_fotos')->nullable()->after('comprobante_url');
        });
        Schema::table('despacho_items', function (Blueprint $table) {
            $table->json('fotos_pago')->nullable()->after('foto_pago');
        });

        // Lo que ya había pasa a ser una lista de uno.
        DB::statement("UPDATE ordenes SET factura_fotos = JSON_ARRAY(factura_foto_url) WHERE factura_foto_url IS NOT NULL AND factura_foto_url <> ''");
        DB::statement("UPDATE pagos SET comprobante_fotos = JSON_ARRAY(comprobante_url) WHERE comprobante_url IS NOT NULL AND comprobante_url <> ''");
        DB::statement("UPDATE despacho_items SET fotos_pago = JSON_ARRAY(foto_pago) WHERE foto_pago IS NOT NULL AND foto_pago <> ''");
    }

    public function down(): void
    {
        Schema::table('ordenes', fn (Blueprint $t) => $t->dropColumn('factura_fotos'));
        Schema::table('pagos', fn (Blueprint $t) => $t->dropColumn('comprobante_fotos'));
        Schema::table('despacho_items', fn (Blueprint $t) => $t->dropColumn('fotos_pago'));
    }
};
