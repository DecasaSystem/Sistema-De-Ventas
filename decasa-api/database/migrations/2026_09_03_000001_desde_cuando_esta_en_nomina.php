<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Desde cuándo se le paga a alguien.
 *
 * Los ciclos pendientes se calculaban desde `created_at`, o sea desde que la
 * persona existe como trabajador. Pero un tapicero creado en junio al que se
 * le asigna sueldo hoy no tiene cuatro quincenas atrasadas: entra a nómina
 * hoy. Sin esta fecha, agregar gente disparaba de una un montón de pagos que
 * nadie debe.
 *
 * Se rellena con hoy para los que ya están asignados, que es justo el caso que
 * se acaba de dar: 45 personas cargadas de una y todas con retroactivos que no
 * existen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->date('nomina_desde')->nullable()->after('nomina_sueldo_id');
        });

        DB::table('usuarios')
            ->whereNotNull('nomina_sueldo_id')
            ->update(['nomina_desde' => now()->toDateString()]);
    }

    public function down(): void
    {
        Schema::table('usuarios', fn (Blueprint $t) => $t->dropColumn('nomina_desde'));
    }
};
