<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acta de satisfacción: el cliente firma al recibir que el producto llegó y
 * llegó bien.
 *
 * Quien firma no siempre es el cliente —puede ser un familiar, la empleada o el
 * portero—, por eso se guarda su nombre y cédula: si después alguien dice que
 * nunca le llegó, eso es lo que resuelve la discusión.
 *
 * La conformidad se registra explícitamente. Si solo se pudiera firmar "recibí",
 * el conductor lo haría firmar igual y nunca quedaría constancia de que la mesa
 * venía rayada; anotarlo en el momento, con el cliente delante, es lo que hace
 * útil el acta cuando el reclamo llega tres días después.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('despacho_items', function (Blueprint $table) {
            if (! Schema::hasColumn('despacho_items', 'firma_recibido_url')) {
                $table->string('firma_recibido_url', 500)->nullable()->after('foto_pago');
            }
            if (! Schema::hasColumn('despacho_items', 'recibido_por_nombre')) {
                $table->string('recibido_por_nombre', 150)->nullable()->after('firma_recibido_url');
            }
            if (! Schema::hasColumn('despacho_items', 'recibido_por_cedula')) {
                $table->string('recibido_por_cedula', 40)->nullable()->after('recibido_por_nombre');
            }
            // null = entregas anteriores al acta; true = conforme; false = con novedad
            if (! Schema::hasColumn('despacho_items', 'conforme')) {
                $table->boolean('conforme')->nullable()->after('recibido_por_cedula');
            }
            if (! Schema::hasColumn('despacho_items', 'observaciones_entrega')) {
                $table->string('observaciones_entrega', 500)->nullable()->after('conforme');
            }
            if (! Schema::hasColumn('despacho_items', 'foto_novedad_url')) {
                $table->string('foto_novedad_url', 500)->nullable()->after('observaciones_entrega');
            }
            // Cuando no hubo quien firmara: queda el motivo, no un vacío
            if (! Schema::hasColumn('despacho_items', 'firma_omitida_motivo')) {
                $table->string('firma_omitida_motivo', 300)->nullable()->after('foto_novedad_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('despacho_items', function (Blueprint $table) {
            $table->dropColumn([
                'firma_recibido_url',
                'recibido_por_nombre',
                'recibido_por_cedula',
                'conforme',
                'observaciones_entrega',
                'foto_novedad_url',
                'firma_omitida_motivo',
            ]);
        });
    }
};
