<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Surtir y Traslado separados (docs/metodologia-de-trabajo-por-rol.md, fase 7).
 *
 * Eran un solo permiso, `acceso_surtir`. En la pantalla, quien no era
 * supervisor solo veía Traslados (de su tienda a otra); crear un surtido
 * (de la fábrica a las tiendas) solo le salía al supervisor. Ahora son dos:
 *
 * - `acceso_traslado`: mover mercancía de una tienda a otra. Lo tiene quien
 *   tenía `acceso_surtir`, y todos los supervisores.
 * - `acceso_surtir`: mandar de la fábrica a las tiendas. Se queda solo en los
 *   supervisores que lo tenían, que eran los únicos que lo usaban en pantalla.
 *   Los demás solo lo tenían por la API, sin pantalla: se les quita.
 *
 * Nadie pierde nada de lo que hacía en la app. Solo agrega una columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('usuarios', 'acceso_traslado')) {
            Schema::table('usuarios', fn (Blueprint $t) => $t->boolean('acceso_traslado')->default(false));
        }

        DB::table('usuarios')
            ->where(fn ($q) => $q->where('acceso_surtir', true)->orWhere('rol', 'supervisor'))
            ->update(['acceso_traslado' => true]);

        DB::table('usuarios')->where('rol', '!=', 'supervisor')->update(['acceso_surtir' => false]);
    }

    /** Vuelve a dejar Surtir a quien tenía Traslado (era un solo permiso). */
    public function down(): void
    {
        if (! Schema::hasColumn('usuarios', 'acceso_traslado')) return;

        DB::table('usuarios')->where('acceso_traslado', true)->update(['acceso_surtir' => true]);
        Schema::table('usuarios', fn (Blueprint $t) => $t->dropColumn('acceso_traslado'));
    }
};
