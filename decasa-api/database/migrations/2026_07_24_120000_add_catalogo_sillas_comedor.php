<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Agrega el catálogo de Sillas de comedor a la tabla configuracion, junto
     * con los demás catálogos (clave catalogo_*). Lo leen el panel de
     * herramientas de redes/home y los agentes de IA.
     */
    public function up(): void
    {
        DB::table('configuracion')->updateOrInsert(
            ['clave' => 'catalogo_sillas_comedor'],
            ['valor' => 'https://drive.google.com/uc?export=download&id=1BDcEAsBW-J8hvjuIhJFhdWJYmXeG_wXV'],
        );
    }

    public function down(): void
    {
        DB::table('configuracion')->where('clave', 'catalogo_sillas_comedor')->delete();
    }
};
