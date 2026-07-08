<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tabla de contadores por grupo de tiendas
        Schema::create('orden_secuencias', function (Blueprint $table) {
            $table->string('grupo', 50)->primary();
            $table->unsignedInteger('ultimo_numero')->default(0);
        });

        // 2. Quitar UNIQUE global en numero_orden (cada grupo maneja su propia secuencia)
        Schema::table('ordenes', function (Blueprint $table) {
            $table->dropUnique('ordenes_numero_orden_unique');
            $table->string('grupo_secuencia', 50)->nullable()->after('numero_orden');
        });

        // 3. Etiquetar órdenes existentes de Pereira como grupo 'pereira'
        DB::statement("
            UPDATE ordenes o
            JOIN tiendas t ON t.id = o.tienda_id
            SET o.grupo_secuencia = 'pereira'
            WHERE t.nombre IN ('Decasa Unicentro Pereira', 'Decasa Circunvalar')
        ");

        // 4. Asignar numero_orden = 1208 a la última orden de Circunvalar
        //    (la que el usuario ya creó y debe llevar ese número)
        DB::statement("
            UPDATE ordenes o
            JOIN tiendas t ON t.id = o.tienda_id
            SET o.numero_orden = 1208
            WHERE t.nombre = 'Decasa Circunvalar'
              AND o.estado != 'borrador'
              AND o.numero_orden IS NOT NULL
            ORDER BY o.id DESC
            LIMIT 1
        ");

        // 5. Seed del grupo pereira en 1208 (siguiente orden será 1209)
        DB::table('orden_secuencias')->insert([
            'grupo'          => 'pereira',
            'ultimo_numero'  => 1208,
        ]);
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
