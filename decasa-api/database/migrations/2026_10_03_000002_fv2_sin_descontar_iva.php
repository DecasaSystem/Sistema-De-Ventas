<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Algunas FV2 se comisionan sin quitarles el IVA.
 *
 * El caso es el de don Henry: cuando llega alguien de la familia del dueño lo
 * mandan donde él, y le hace una FV2 con descuento. A ESA comisión no se le
 * divide por 1,19. No es a todas las FV2 ni a todos los trabajadores, así que
 * son dos marcas:
 *
 *   usuarios.puede_fv2_sin_iva  -> a quién le aparece el switch al crear la FV2
 *                                  (se prende desde Trabajadores).
 *   ordenes.sin_descontar_iva   -> la orden a la que se le prendió.
 *
 * Y se arreglan las dos que ya existen y se estaban cobrando con el IVA
 * quitado: la FV2-9 y la FV2-10.
 */
return new class extends Migration
{
    private const ORDENES = [9, 10];

    public function up(): void
    {
        if (! Schema::hasColumn('usuarios', 'puede_fv2_sin_iva')) {
            Schema::table('usuarios', function (Blueprint $t) {
                $t->boolean('puede_fv2_sin_iva')->default(false)->after('ve_todas_ordenes');
            });
        }

        if (! Schema::hasColumn('ordenes', 'sin_descontar_iva')) {
            Schema::table('ordenes', function (Blueprint $t) {
                $t->boolean('sin_descontar_iva')->default(false)->after('motivo_serie');
            });
        }

        foreach (self::ORDENES as $numero) {
            $n = DB::table('ordenes')->where('serie', 'FV2')->where('serie_numero', $numero)
                ->update(['sin_descontar_iva' => true]);

            if (! $n) Log::warning("FV2-{$numero}: no existe, no se marcó como sin descontar IVA.");
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ordenes', 'sin_descontar_iva')) {
            Schema::table('ordenes', fn (Blueprint $t) => $t->dropColumn('sin_descontar_iva'));
        }
        if (Schema::hasColumn('usuarios', 'puede_fv2_sin_iva')) {
            Schema::table('usuarios', fn (Blueprint $t) => $t->dropColumn('puede_fv2_sin_iva'));
        }
    }
};
