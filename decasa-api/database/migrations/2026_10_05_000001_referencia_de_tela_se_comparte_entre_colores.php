<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una referencia de tela se comparte entre sus colores.
 *
 * La referencia nació única porque en el Excel con que se cargaron las telas
 * cada una llevaba el color adentro ("ADARA 10 HUMO"). Pero así no se trabaja:
 * el proveedor tiene una referencia y la vende en varios colores, y la tela
 * se crea con su nombre, su referencia y sus colores. Con la referencia única
 * el segundo color chocaba contra la base y daba un error del servidor —
 * también el botón "Otro color", que la copia—.
 *
 * Lo que distingue una tela de otra sigue siendo proveedor + nombre + color,
 * que sigue siendo único.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('catalogo_telas', 'catalogo_telas_referencia_unique')) {
            Schema::table('catalogo_telas', function (Blueprint $table) {
                $table->dropUnique('catalogo_telas_referencia_unique');
            });
        }
    }

    public function down(): void
    {
        // No se vuelve a poner: con colores que ya comparten referencia, el
        // índice no se podría crear.
    }
};
