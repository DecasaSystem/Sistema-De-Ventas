<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo de cotizaciones.
 *
 * Una cotización es una propuesta comercial: el cliente pregunta cuánto le sale
 * algo y todavía no compra. Vive en la tabla `ordenes` para reusar orden_items
 * (variantes, combos, personalizados, bocetos), pero no reserva inventario,
 * no exige stock, no genera comisión y no consume consecutivo de orden.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Nuevo estado 'cotizacion' en el enum de ordenes.
        DB::statement("
            ALTER TABLE ordenes MODIFY COLUMN estado
            ENUM(
                'cotizacion',
                'borrador',
                'pendiente_cotizacion',
                'pendiente_anticipo',
                'en_produccion',
                'listo_entrega',
                'en_camino',
                'entregado',
                'cancelado'
            ) NOT NULL DEFAULT 'pendiente_anticipo'
        ");

        // 2. cliente_id pasa a ser opcional: en una cotización el cliente puede
        //    no dar ni el nombre. La foreign key se conserva.
        DB::statement('ALTER TABLE ordenes MODIFY COLUMN cliente_id BIGINT UNSIGNED NULL');

        // 3. Campos propios de la cotización (todos nullable: no afectan órdenes).
        Schema::table('ordenes', function (Blueprint $table) {
            if (! Schema::hasColumn('ordenes', 'cotizacion_estado')) {
                $table->enum('cotizacion_estado', ['abierta', 'enviada', 'convertida', 'perdida'])
                    ->nullable()
                    ->after('grupo_secuencia');
            }
            if (! Schema::hasColumn('ordenes', 'cotizacion_numero')) {
                $table->unsignedInteger('cotizacion_numero')->nullable()->after('cotizacion_estado');
            }
            if (! Schema::hasColumn('ordenes', 'cotizacion_valida_hasta')) {
                $table->date('cotizacion_valida_hasta')->nullable()->after('cotizacion_numero');
            }
            if (! Schema::hasColumn('ordenes', 'motivo_perdida')) {
                $table->string('motivo_perdida', 300)->nullable()->after('cotizacion_valida_hasta');
            }
            // Contacto suelto: para cuando el cliente da un dato pero no se crea
            // cliente formal. Al convertir en orden se crea el cliente de verdad.
            if (! Schema::hasColumn('ordenes', 'contacto_nombre')) {
                $table->string('contacto_nombre', 150)->nullable()->after('motivo_perdida');
            }
            if (! Schema::hasColumn('ordenes', 'contacto_telefono')) {
                $table->string('contacto_telefono', 40)->nullable()->after('contacto_nombre');
            }
            if (! Schema::hasColumn('ordenes', 'contacto_email')) {
                $table->string('contacto_email', 150)->nullable()->after('contacto_telefono');
            }
        });

        // 4. Índice para el listado del módulo (idempotente).
        $idx = collect(DB::select("SHOW INDEX FROM ordenes WHERE Key_name = 'idx_ordenes_cotizacion'"));
        if ($idx->isEmpty()) {
            DB::statement('CREATE INDEX idx_ordenes_cotizacion ON ordenes (estado, cotizacion_estado)');
        }

        // 5. Secuencias COT-N por grupo de tienda, separadas de las de órdenes
        //    para no gastar consecutivos reales (Armenia va en 4261).
        foreach (['cot_armenia', 'cot_pereira'] as $grupo) {
            DB::statement("
                INSERT INTO orden_secuencias (grupo, ultimo_numero)
                VALUES ('{$grupo}', 0)
                ON DUPLICATE KEY UPDATE ultimo_numero = ultimo_numero
            ");
        }
    }

    public function down(): void
    {
        DB::table('orden_secuencias')->whereIn('grupo', ['cot_armenia', 'cot_pereira'])->delete();

        $idx = collect(DB::select("SHOW INDEX FROM ordenes WHERE Key_name = 'idx_ordenes_cotizacion'"));
        if ($idx->isNotEmpty()) {
            DB::statement('DROP INDEX idx_ordenes_cotizacion ON ordenes');
        }

        Schema::table('ordenes', function (Blueprint $table) {
            $table->dropColumn([
                'cotizacion_estado',
                'cotizacion_numero',
                'cotizacion_valida_hasta',
                'motivo_perdida',
                'contacto_nombre',
                'contacto_telefono',
                'contacto_email',
            ]);
        });

        // Ojo: revertir cliente_id a NOT NULL falla si quedan cotizaciones sin
        // cliente. Se borran primero.
        DB::table('ordenes')->where('estado', 'cotizacion')->delete();
        DB::statement('ALTER TABLE ordenes MODIFY COLUMN cliente_id BIGINT UNSIGNED NOT NULL');

        DB::statement("
            ALTER TABLE ordenes MODIFY COLUMN estado
            ENUM(
                'borrador',
                'pendiente_cotizacion',
                'pendiente_anticipo',
                'en_produccion',
                'listo_entrega',
                'en_camino',
                'entregado',
                'cancelado'
            ) NOT NULL DEFAULT 'pendiente_anticipo'
        ");
    }
};
