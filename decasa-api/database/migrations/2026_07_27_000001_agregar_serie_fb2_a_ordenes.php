<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Órdenes de serie especial (FB2): ventas con descuento especial autorizado.
 * No llevan consecutivo normal de orden sino su
 * propia serie (FB2-1, FB2-2...), pero por lo demás son ventas reales: descuentan
 * inventario, generan comisión y cuentan en estadísticas.
 *
 * numero_orden es unsignedInteger y no admite texto, por eso la serie va aparte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            if (! Schema::hasColumn('ordenes', 'serie')) {
                $table->string('serie', 10)->nullable()->after('numero_orden');
            }
            if (! Schema::hasColumn('ordenes', 'serie_numero')) {
                $table->unsignedInteger('serie_numero')->nullable()->after('serie');
            }
            if (! Schema::hasColumn('ordenes', 'motivo_serie')) {
                $table->string('motivo_serie', 300)->nullable()->after('serie_numero');
            }
        });

        $idx = collect(DB::select("SHOW INDEX FROM ordenes WHERE Key_name = 'idx_ordenes_serie'"));
        if ($idx->isEmpty()) {
            DB::statement('CREATE INDEX idx_ordenes_serie ON ordenes (serie, serie_numero)');
        }

        // Consecutivo único para toda la empresa: estas órdenes son transversales
        // a las tiendas y son pocas, así que no se separan por grupo.
        DB::statement("
            INSERT INTO orden_secuencias (grupo, ultimo_numero)
            VALUES ('fb2', 0)
            ON DUPLICATE KEY UPDATE ultimo_numero = ultimo_numero
        ");
    }

    public function down(): void
    {
        DB::table('orden_secuencias')->where('grupo', 'fb2')->delete();

        $idx = collect(DB::select("SHOW INDEX FROM ordenes WHERE Key_name = 'idx_ordenes_serie'"));
        if ($idx->isNotEmpty()) {
            DB::statement('DROP INDEX idx_ordenes_serie ON ordenes');
        }

        Schema::table('ordenes', function (Blueprint $table) {
            $table->dropColumn(['serie', 'serie_numero', 'motivo_serie']);
        });
    }
};
