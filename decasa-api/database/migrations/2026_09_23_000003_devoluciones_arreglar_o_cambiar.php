<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuando algo se daña —en el camino o antes de salir—, hay tres salidas y no
 * dos: se arregla, se cambia por otra unidad de lo mismo, o se cambia por
 * otro producto (que puede valer más, menos o lo mismo). Hasta ahora solo
 * existían "vuelve al taller" y "se cancela y se devuelve la plata".
 *
 * El vendedor deja anotado qué le ofreció al cliente y qué prefirió
 * (`preferencia_cliente`); decide el supervisor. Son cosas distintas y por
 * eso van en columnas distintas: lo que el cliente quiere no siempre es lo
 * que se puede.
 *
 *   cambio_mismo → otra unidad igual: de stock se aparta otra, lo fabricado
 *                  se hace de nuevo. La orden no cambia de valor.
 *   cambio       → por otro producto: lo devuelto deja de cobrarse y el
 *                  vendedor agrega el nuevo desde Editar. Ya existía para lo
 *                  entregado; ahora también aplica antes de la entrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devoluciones', function (Blueprint $table) {
            $table->enum('preferencia_cliente', ['arreglar', 'cambiar_mismo', 'cambiar_otro'])
                ->nullable()->after('foto_url');
        });

        DB::statement("ALTER TABLE devoluciones MODIFY COLUMN estado ENUM('pendiente','a_produccion','reembolsada','cambio','cambio_mismo') NOT NULL DEFAULT 'pendiente'");
    }

    public function down(): void
    {
        DB::statement("UPDATE devoluciones SET estado = 'a_produccion' WHERE estado = 'cambio_mismo'");
        DB::statement("ALTER TABLE devoluciones MODIFY COLUMN estado ENUM('pendiente','a_produccion','reembolsada','cambio') NOT NULL DEFAULT 'pendiente'");
        Schema::table('devoluciones', fn (Blueprint $t) => $t->dropColumn('preferencia_cliente'));
    }
};
