<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los catálogos pasan a ser herramientas como las demás.
 *
 * Estaban aparte, en la tabla `configuracion`, y no había pantalla donde
 * editarlos: sólo se leían. Así que en el panel del asesor aparecían quince
 * enlaces que nadie podía cambiar, y en el editor de Gestión no aparecían.
 * Dos sitios distintos para lo mismo: uno editable y otro no.
 *
 * Se traen a `herramientas`, que es donde vive lo que el asesor copia. Y se
 * traen con su clave original —de ahí la columna nueva— para que lo que ya
 * consumía esos enlaces siga recibiendo exactamente lo mismo.
 *
 * Las filas viejas de `configuracion` NO se borran: si algún flujo externo las
 * lee directo, no se queda sin nada de un día para otro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('herramientas', function (Blueprint $table) {
            // Sólo la tienen las herramientas que algo más busca por nombre.
            // Las que crea una empresa desde Gestión van sin clave.
            $table->string('clave', 60)->nullable()->unique()->after('id');
        });

        $nombres = [
            'bases_comedores'   => 'Bases de comedor',  'sillas_comedor'   => 'Sillas de comedor',
            'sillas_auxiliares' => 'Sillas auxiliares', 'sillas_barra'     => 'Sillas de barra',
            'mesas_centro'      => 'Mesas de centro',   'mesas_auxiliares' => 'Mesas auxiliares',
            'mesas_noche'       => 'Mesas de noche',    'mesas_tv'         => 'Mesas de TV',
            'sofas'             => 'Sofás',             'sofas_modulares'  => 'Sofás modulares',
            'sofas_camas'       => 'Sofá camas',        'camas'            => 'Camas',
            'colchones'         => 'Colchones',         'cajoneros_bifes'  => 'Cajoneros / Bifés',
            'escritorios'       => 'Escritorios',
        ];

        $filas = DB::table('configuracion')->where('clave', 'like', 'catalogo_%')->get(['clave', 'valor']);
        $ahora = now();
        $orden = 0;
        $nuevas = [];

        foreach ($filas as $fila) {
            $corta = str_replace('catalogo_', '', $fila->clave);

            // La promoción del 20% venció y el panel ya los escondía: no vale
            // la pena arrastrarlos.
            if (str_starts_with($corta, 'descuento')) {
                continue;
            }

            $nuevas[] = [
                'clave'      => $fila->clave,
                'seccion'    => 'Catálogos',
                'titulo'     => $nombres[$corta] ?? ucfirst(str_replace('_', ' ', $corta)),
                'tipo'       => 'enlace',
                'contenido'  => $fila->valor,
                'subtitulo'  => null,
                'icono'      => 'DocumentTextIcon',
                'activo'     => true,
                'orden'      => $orden += 10,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
        }

        if ($nuevas) {
            DB::table('herramientas')->insert($nuevas);
        }
    }

    public function down(): void
    {
        DB::table('herramientas')->whereNotNull('clave')->where('clave', 'like', 'catalogo_%')->delete();

        Schema::table('herramientas', function (Blueprint $table) {
            $table->dropUnique(['clave']);
            $table->dropColumn('clave');
        });
    }
};
