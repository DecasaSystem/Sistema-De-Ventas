<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Etiquetar órdenes existentes de Armenia (todas las tiendas que no son Pereira)
        DB::statement("
            UPDATE ordenes o
            JOIN tiendas t ON t.id = o.tienda_id
            SET o.grupo_secuencia = 'armenia'
            WHERE t.nombre IN ('Decasa Norte', 'Decasa Vía El Edén', 'Decasa Vía Jardines', 'Bodega Fábrica', 'Tienda Virtual')
              AND (o.grupo_secuencia IS NULL OR o.grupo_secuencia != 'armenia')
        ");

        // 2. Seed del grupo armenia en 4226 — la siguiente orden que se cree
        //    tomará 4227. Se digitalizarán manualmente las órdenes históricas
        //    4227-4252; la 4253 (última hecha) ya existe en el sistema.
        //    INSERT OR UPDATE idempotente (nunca baja el contador si ya subió).
        DB::statement("
            INSERT INTO orden_secuencias (grupo, ultimo_numero)
            VALUES ('armenia', 4226)
            ON DUPLICATE KEY UPDATE ultimo_numero = GREATEST(ultimo_numero, 4226)
        ");
    }

    public function down(): void
    {
        DB::table('orden_secuencias')->where('grupo', 'armenia')->delete();

        DB::statement("
            UPDATE ordenes o
            JOIN tiendas t ON t.id = o.tienda_id
            SET o.grupo_secuencia = NULL
            WHERE t.nombre IN ('Decasa Norte', 'Decasa Vía El Edén', 'Decasa Vía Jardines', 'Bodega Fábrica', 'Tienda Virtual')
              AND o.grupo_secuencia = 'armenia'
        ");
    }
};
