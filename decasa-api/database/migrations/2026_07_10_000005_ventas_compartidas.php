<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Campos en ordenes para registrar venta compartida
        Schema::table('ordenes', function (Blueprint $table) {
            if (! Schema::hasColumn('ordenes', 'es_compartida')) {
                $table->boolean('es_compartida')->default(false)->after('notas');
            }
            if (! Schema::hasColumn('ordenes', 'covendedor_id')) {
                $table->unsignedBigInteger('covendedor_id')->nullable()->after('es_compartida');
                $table->foreign('covendedor_id')->references('id')->on('usuarios')->nullOnDelete();
            }
        });

        // Verificar si el índice compuesto (orden_id, vendedor_id) ya existe
        $tieneCompuesto = collect(DB::select("SHOW INDEX FROM comisiones WHERE Key_name = 'comisiones_orden_id_vendedor_id_unique'"))->isNotEmpty();
        $tieneSimple    = collect(DB::select("SHOW INDEX FROM comisiones WHERE Key_name = 'comisiones_orden_id_unique'"))->isNotEmpty();

        // Paso 1: agregar el índice compuesto si no existe
        if (! $tieneCompuesto) {
            Schema::table('comisiones', function (Blueprint $table) {
                $table->unique(['orden_id', 'vendedor_id']);
            });
        }

        // Paso 2: eliminar el índice simple si aún existe
        if ($tieneSimple) {
            Schema::table('comisiones', function (Blueprint $table) {
                $table->dropUnique(['orden_id']);
            });
        }
    }

    public function down(): void
    {
        $tieneSimple    = collect(DB::select("SHOW INDEX FROM comisiones WHERE Key_name = 'comisiones_orden_id_unique'"))->isNotEmpty();
        $tieneCompuesto = collect(DB::select("SHOW INDEX FROM comisiones WHERE Key_name = 'comisiones_orden_id_vendedor_id_unique'"))->isNotEmpty();

        if (! $tieneSimple) {
            Schema::table('comisiones', function (Blueprint $table) {
                $table->unique(['orden_id']);
            });
        }

        if ($tieneCompuesto) {
            Schema::table('comisiones', function (Blueprint $table) {
                $table->dropUnique(['orden_id', 'vendedor_id']);
            });
        }

        Schema::table('ordenes', function (Blueprint $table) {
            if (Schema::hasColumn('ordenes', 'covendedor_id')) {
                $table->dropForeign(['covendedor_id']);
                $table->dropColumn('covendedor_id');
            }
            if (Schema::hasColumn('ordenes', 'es_compartida')) {
                $table->dropColumn('es_compartida');
            }
        });
    }
};
