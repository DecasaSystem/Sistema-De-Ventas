<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Descontar tela deja de depender de llamarse "Costurero".
 *
 * El programa se va a vender a otras empresas, y ahí los oficios son otros: un
 * taller de metal no tiene costureros, tiene soldadores. Cualquier cosa que
 * pregunte `rol === 'costurero'` obliga a que la empresa nueva le ponga a su
 * gente un nombre de oficio ajeno para que el programa funcione, que es
 * justo lo contrario de poder crear sus propios roles.
 *
 * Pasa a ser un permiso más, como los otros: se marca por trabajador, y el
 * rol se llama como cada empresa quiera.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->boolean('acceso_telas')->default(false)->after('recarga_telas');
        });

        // Quien ya descontaba tela sigue descontándola: los costureros lo hacían
        // por su rol y los supervisores por serlo. Sin esto, el día del cambio
        // el taller se queda sin poder registrar el consumo.
        DB::table('usuarios')
            ->where(fn ($q) => $q->where('rol', 'costurero')->orWhere('rol', 'supervisor'))
            ->update(['acceso_telas' => true]);
    }

    public function down(): void
    {
        Schema::table('usuarios', fn (Blueprint $t) => $t->dropColumn('acceso_telas'));
    }
};
