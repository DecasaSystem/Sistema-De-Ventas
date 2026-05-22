<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Agrega tarifa_hora como campo independiente del salario base.
// El salario es fijo (nómina); tarifa_hora es la tasa del incentivo por pieza.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salarios_cargo', function (Blueprint $table) {
            $table->decimal('tarifa_hora', 10, 2)->default(0)->after('dias_laborales_mes');
        });

        // Inicializa con el valor derivado del salario actual para no romper los cálculos existentes
        DB::table('salarios_cargo')->get()->each(function ($s) {
            $tarifaHora = $s->dias_laborales_mes > 0
                ? round($s->salario_mensual / $s->dias_laborales_mes / 8, 2)
                : 0;
            DB::table('salarios_cargo')->where('id', $s->id)->update(['tarifa_hora' => $tarifaHora]);
        });
    }

    public function down(): void
    {
        Schema::table('salarios_cargo', function (Blueprint $table) {
            $table->dropColumn('tarifa_hora');
        });
    }
};
