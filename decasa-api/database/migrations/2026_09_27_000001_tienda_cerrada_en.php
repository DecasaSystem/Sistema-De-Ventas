<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Desde cuándo está cerrada una tienda.
 *
 * Comisiones arrastra la meta y el equipo de cada tienda mes a mes hasta que
 * alguien los cambie. Con una tienda que cierra eso sale mal solo: al mes
 * siguiente sigue con su meta de $40M contra $0 vendido —y en una tienda
 * trimestral ese mes en rojo se come el trimestre entero—, y su gente sigue
 * pesando días en un equipo que ya no existe.
 *
 * Con la fecha de cierre, meta y equipo dejan de arrastrarse a los meses
 * posteriores al del cierre. El mes en que cerró se queda como estaba: ahí sí
 * hubo ventas y gente.
 *
 * Circunvalar es la que ya pasó: cerró el 27 de agosto de 2026 (es la fecha
 * del traslado de Genesis a Unicentro). Cualquier otra que estuviera inactiva
 * sin fecha queda cerrada desde su última venta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiendas', function (Blueprint $table) {
            $table->date('cerrada_en')->nullable()->after('activa');
        });

        DB::table('tiendas')->where('nombre', 'Decasa Circunvalar')->where('activa', false)
            ->update(['cerrada_en' => '2026-08-27']);

        foreach (DB::table('tiendas')->where('activa', false)->whereNull('cerrada_en')->get() as $t) {
            $ultima = DB::table('ordenes')->where('tienda_id', $t->id)
                ->whereNotIn('estado', ['cancelado', 'cotizacion', 'borrador'])
                ->max('created_at');
            DB::table('tiendas')->where('id', $t->id)->update([
                'cerrada_en' => $ultima ? substr($ultima, 0, 10) : now()->toDateString(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('tiendas', fn (Blueprint $t) => $t->dropColumn('cerrada_en'));
    }
};
