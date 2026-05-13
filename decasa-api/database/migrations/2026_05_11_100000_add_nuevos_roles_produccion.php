<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nuevos roles: ebanista y despachador
        DB::statement("ALTER TABLE usuarios MODIFY COLUMN rol ENUM('vendedor','supervisor','conductor','ebanista','despachador') NOT NULL DEFAULT 'vendedor'");

        // Flag de tapicero (solo aplica a supervisores)
        Schema::table('usuarios', function (Blueprint $table) {
            $table->boolean('es_tapicero')->default(false)->after('facturacion');
        });

        // Nuevo estado intermedio: pendiente_despachador (todos los pasos terminaron, espera al despachador)
        DB::statement("ALTER TABLE produccion MODIFY COLUMN estado ENUM('pendiente','en_proceso','listo','retrasado','entregado','cancelado','pendiente_despachador') NOT NULL DEFAULT 'pendiente'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE usuarios MODIFY COLUMN rol ENUM('vendedor','supervisor','conductor') NOT NULL DEFAULT 'vendedor'");

        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn('es_tapicero');
        });

        DB::statement("ALTER TABLE produccion MODIFY COLUMN estado ENUM('pendiente','en_proceso','listo','retrasado','entregado','cancelado') NOT NULL DEFAULT 'pendiente'");
    }
};
