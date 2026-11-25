<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat de la orden (usuario, 2026-11-25):
 *  - `clave_envio`: la pantalla manda una clave por envío; si la señal se corta
 *    y se reintenta, el servidor devuelve el mismo mensaje en vez de guardarlo
 *    dos veces.
 *  - `editado_at` / `eliminado_at`: el autor corrige o borra su mensaje y queda
 *    marcado con la fecha y la hora, para que se sepa.
 *  - `ediciones`: lo que decía antes de cada cambio (el rastro; no se pierde).
 * Solo agrega columnas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orden_mensajes', function (Blueprint $table) {
            if (! Schema::hasColumn('orden_mensajes', 'clave_envio')) {
                $table->string('clave_envio', 64)->nullable()->index();
            }
            if (! Schema::hasColumn('orden_mensajes', 'editado_at')) {
                $table->timestamp('editado_at')->nullable();
            }
            if (! Schema::hasColumn('orden_mensajes', 'eliminado_at')) {
                $table->timestamp('eliminado_at')->nullable();
            }
            if (! Schema::hasColumn('orden_mensajes', 'ediciones')) {
                $table->json('ediciones')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orden_mensajes', function (Blueprint $table) {
            foreach (['clave_envio', 'editado_at', 'eliminado_at', 'ediciones'] as $col) {
                if (Schema::hasColumn('orden_mensajes', $col)) {
                    if ($col === 'clave_envio') $table->dropIndex(['clave_envio']);
                    $table->dropColumn($col);
                }
            }
        });
    }
};
