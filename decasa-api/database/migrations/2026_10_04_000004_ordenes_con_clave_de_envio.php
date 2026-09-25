<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada orden guarda la clave del envío que la creó, única.
 *
 * A la gente se le duplicaban órdenes: el internet se caía después de que el
 * servidor la guardara, la respuesta no llegaba y la persona volvía a darle.
 * La pantalla manda una clave por formulario y la repite en cada reintento;
 * con la misma clave el servidor devuelve la orden que ya existe. Que sea
 * única en la base es lo que ataja el caso de dos envíos que llegan a la
 * vez: el segundo choca y no se crea.
 *
 * Nullable: las órdenes de antes no tienen, y las que llegan sin clave (una
 * pantalla vieja todavía abierta) siguen con la protección de los 15 segundos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('ordenes', 'clave_envio')) return;

        Schema::table('ordenes', function (Blueprint $t) {
            $t->string('clave_envio', 64)->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('ordenes', 'clave_envio')) return;

        Schema::table('ordenes', function (Blueprint $t) {
            $t->dropUnique(['clave_envio']);
            $t->dropColumn('clave_envio');
        });
    }
};
