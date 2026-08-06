<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dos pasos para restauraciones: destapizar y pelar.
 *
 * Un mueble que llega a restaurar casi nunca entra directo al taller: primero
 * hay que quitarle la tela vieja o el acabado viejo. Ese trabajo existe, lleva
 * su tiempo y hasta ahora no se podía anotar en ninguna parte, así que quedaba
 * escondido dentro de otro paso o directamente sin registrar.
 */
return new class extends Migration
{
    private const TODOS = [
        'ebanisteria', 'tapizado', 'laca', 'esqueleteria', 'pintura', 'costura',
        'destapizar', 'pelar',
    ];

    private const ANTERIORES = [
        'ebanisteria', 'tapizado', 'laca', 'esqueleteria', 'pintura', 'costura',
    ];

    public function up(): void
    {
        $lista = "'" . implode("','", self::TODOS) . "'";
        DB::statement("ALTER TABLE produccion_pasos MODIFY tipo_proceso ENUM($lista) NOT NULL");
    }

    public function down(): void
    {
        // Si ya se usaron, no se puede volver atrás sin perder datos.
        $enUso = DB::table('produccion_pasos')
            ->whereIn('tipo_proceso', ['destapizar', 'pelar'])->count();
        if ($enUso > 0) {
            throw new RuntimeException(
                "Hay {$enUso} pasos de destapizar/pelar registrados. " .
                'Revertir esta migración los borraría.'
            );
        }

        $lista = "'" . implode("','", self::ANTERIORES) . "'";
        DB::statement("ALTER TABLE produccion_pasos MODIFY tipo_proceso ENUM($lista) NOT NULL");
    }
};
