<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo la orden dejó de ser borrador y pasó a ser una venta.
 *
 * La lista se ordenaba por `created_at`, que en un borrador es el día en que
 * el vendedor lo empezó, no el día en que se cerró la venta. Un borrador de
 * hace dos semanas que se completa hoy aparecía enterrado quince órdenes
 * abajo y nadie lo veía, aunque para el taller y facturación es nuevo.
 *
 * Para una orden normal las dos fechas son la misma, así que ordenar por
 * `COALESCE(confirmada_en, created_at)` no altera nada de lo ya existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $table->timestamp('confirmada_en')->nullable()->after('estado');
            $table->index('confirmada_en');
        });

        // Los borradores ya completados no tienen cómo saber su fecha... salvo
        // por el anticipo: se registra en el momento de completar. En una orden
        // normal ese pago nace junto con la orden, así que sólo se rellena
        // cuando de verdad quedó por detrás — es decir, cuando fue un borrador.
        DB::statement("
            UPDATE ordenes o
            JOIN (
                SELECT orden_id, MIN(created_at) AS primer_anticipo
                FROM pagos
                WHERE tipo = 'anticipo'
                GROUP BY orden_id
            ) p ON p.orden_id = o.id
            SET o.confirmada_en = p.primer_anticipo
            WHERE o.estado <> 'borrador'
              AND p.primer_anticipo > o.created_at + INTERVAL 1 DAY
        ");

        // Un borrador se puede completar con anticipo 0, y entonces no hay pago
        // que delate la fecha. Pero una orden que sigue en `pendiente_anticipo`
        // y no tiene un solo pago no ha avanzado desde que se completó: nada la
        // pudo tocar después, así que su `updated_at` ES el momento en que dejó
        // de ser borrador. Fuera de ese caso `updated_at` no sirve — cambia con
        // cada avance de estado — y por eso no se usa más ampliamente.
        DB::statement("
            UPDATE ordenes o
            SET o.confirmada_en = o.updated_at
            WHERE o.confirmada_en IS NULL
              AND o.estado = 'pendiente_anticipo'
              AND o.updated_at > o.created_at + INTERVAL 1 DAY
              AND NOT EXISTS (SELECT 1 FROM pagos p WHERE p.orden_id = o.id)
        ");
    }

    public function down(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $table->dropIndex(['confirmada_en']);
            $table->dropColumn('confirmada_en');
        });
    }
};
