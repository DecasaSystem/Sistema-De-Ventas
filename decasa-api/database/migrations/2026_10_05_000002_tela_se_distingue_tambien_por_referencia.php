<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una tela se distingue también por su referencia.
 *
 * LAYLA 01 CRUDO y LAYLA 02 PERLA son telas distintas aunque las dos sean
 * BEIGE, con sus metros aparte. Con proveedor + nombre + color únicos, la
 * segunda chocaba con la primera. Ahora lo que no puede repetirse es
 * proveedor + nombre + referencia + color, y eso lo cuida el programa
 * (CatalogoTela::igualA): la base no sirve para eso con referencias vacías,
 * que para ella nunca son iguales entre sí.
 *
 * Queda un índice común para seguir buscando rápido por esos tres.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('catalogo_telas', 'catalogo_telas_marca_tipo_color_unique')) {
            Schema::table('catalogo_telas', function (Blueprint $table) {
                $table->dropUnique('catalogo_telas_marca_tipo_color_unique');
            });
        }
        if (! Schema::hasIndex('catalogo_telas', 'catalogo_telas_marca_tipo_color_index')) {
            Schema::table('catalogo_telas', function (Blueprint $table) {
                $table->index(['marca', 'tipo', 'color']);
            });
        }
    }

    public function down(): void
    {
        // No se vuelve a poner: con dos referencias del mismo nombre y color
        // el índice único no se podría crear.
    }
};
