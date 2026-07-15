<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 — recuperación de fichas por similitud semántica (ver AGENT.md).
 *
 * Con 306 fichas, la similitud coseno en memoria es instantánea: no hace falta una vector DB.
 * El embedding se guarda como JSON y se regenera con `php artisan fichas:reindex`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fichas_tecnicas', function (Blueprint $table) {
            $table->json('embedding')->nullable()->after('ruta_excel');
            $table->timestamp('embedding_at')->nullable()->after('embedding');
        });
    }

    public function down(): void
    {
        Schema::table('fichas_tecnicas', function (Blueprint $table) {
            $table->dropColumn(['embedding', 'embedding_at']);
        });
    }
};
