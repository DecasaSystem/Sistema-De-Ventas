<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quién recibe el aviso de "se vendió el último".
 *
 * Antes iba a todos los supervisores, y es un aviso de una sola persona: la que
 * surte. Se activa a mano como el de asignar fecha de entrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->boolean('notif_stock')->default(false)->after('notif_asignar_fecha');
        });

        // Mónica es quien surte hoy. Si mañana es otra persona, se marca desde
        // la ficha del usuario sin tocar código.
        DB::table('usuarios')
            ->where('nombre', 'Monica')
            ->where('rol', 'supervisor')
            ->update(['notif_stock' => true]);
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn('notif_stock');
        });
    }
};
