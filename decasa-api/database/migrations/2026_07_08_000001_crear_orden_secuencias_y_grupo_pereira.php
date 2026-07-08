<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tabla de contadores por grupo de tiendas (idempotente)
        if (! Schema::hasTable('orden_secuencias')) {
            Schema::create('orden_secuencias', function (Blueprint $table) {
                $table->string('grupo', 50)->primary();
                $table->unsignedInteger('ultimo_numero')->default(0);
            });
        }

        // 2. Quitar UNIQUE global en numero_orden (idempotente)
        if (Schema::hasTable('ordenes')) {
            $indexes = collect(DB::select("SHOW INDEX FROM ordenes WHERE Key_name = 'ordenes_numero_orden_unique'"));
            if ($indexes->isNotEmpty()) {
                Schema::table('ordenes', function (Blueprint $table) {
                    $table->dropUnique('ordenes_numero_orden_unique');
                });
            }
            if (! Schema::hasColumn('ordenes', 'grupo_secuencia')) {
                Schema::table('ordenes', function (Blueprint $table) {
                    $table->string('grupo_secuencia', 50)->nullable()->after('numero_orden');
                });
            }
        }

        // 3. Etiquetar órdenes existentes de Pereira
        DB::statement("
            UPDATE ordenes o
            JOIN tiendas t ON t.id = o.tienda_id
            SET o.grupo_secuencia = 'pereira'
            WHERE t.nombre IN ('Decasa Unicentro Pereira', 'Decasa Circunvalar')
              AND (o.grupo_secuencia IS NULL OR o.grupo_secuencia != 'pereira')
        ");

        // 4. Asignar numero_orden = 1208 a la última orden confirmada de Circunvalar
        DB::statement("
            UPDATE ordenes
            SET numero_orden = 1208, grupo_secuencia = 'pereira'
            WHERE id = (
                SELECT max_id FROM (
                    SELECT MAX(o.id) AS max_id
                    FROM ordenes o
                    JOIN tiendas t ON t.id = o.tienda_id
                    WHERE t.nombre = 'Decasa Circunvalar'
                      AND o.estado != 'borrador'
                ) sub
            )
              AND numero_orden != 1208
        ");

        // 5. Seed del grupo pereira en 1208 — INSERT OR UPDATE idempotente
        DB::statement("
            INSERT INTO orden_secuencias (grupo, ultimo_numero)
            VALUES ('pereira', 1208)
            ON DUPLICATE KEY UPDATE ultimo_numero = GREATEST(ultimo_numero, 1208)
        ");
    }

    public function down(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $table->dropColumn('grupo_secuencia');
            $table->unique('numero_orden');
        });

        Schema::dropIfExists('orden_secuencias');
    }
};
