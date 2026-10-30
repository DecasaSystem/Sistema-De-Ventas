<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nombre de cada hoja del catálogo (2026-10-30, pedido del usuario: "ponerle
 * nombre a las hojas del catálogo para que [Elena] pueda saber qué hoja le
 * está mandando").
 *
 * No se reutiliza `nota`: la nota la VE el cliente (letrero encima de la hoja
 * en el visor). El nombre es para la gente de la tienda y para los agentes de
 * chat ("Camas Bali y Roma"), que lo usan para mandar esa hoja puntual con un
 * enlace /c/<slug>?hoja=<id>. Los agentes lo leen como columna opcional
 * (CONTRATO_OPCIONAL en core/contrato-bd.js).
 *
 * Solo agrega la columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('catalogo_paginas') && ! Schema::hasColumn('catalogo_paginas', 'nombre')) {
            Schema::table('catalogo_paginas', function (Blueprint $t) {
                $t->string('nombre', 120)->nullable()->after('imagen_url');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('catalogo_paginas', 'nombre')) {
            Schema::table('catalogo_paginas', function (Blueprint $t) {
                $t->dropColumn('nombre');
            });
        }
    }
};
