<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los procesos del taller pasan a ser datos, no código.
 *
 * Estaban en un enum, así que cada proceso nuevo o cada cambio de nombre era
 * una migración. El taller sabe mejor que nadie qué pasos hace y cómo los
 * llama, y ahora puede mantenerlos solo.
 *
 * La columna tipo_proceso deja de ser enum y pasa a texto: guarda la 'clave'
 * del tipo. Las filas existentes no se tocan — su clave ya es el valor que
 * tenían. Se deja como texto suelto a propósito, sin llave foránea: si algún
 * día alguien borra un tipo, un paso ya trabajado no debe desaparecer con él.
 */
return new class extends Migration
{
    /** Los que ya existían, con quién los hace y de qué color se ven hoy. */
    private const INICIALES = [
        ['pelar',        'Pelar',        'Quitar el acabado viejo (restauración)', 'stone',  ['ebanista']],
        ['destapizar',   'Destapizar',   'Quitar la tela vieja (restauración)',    'rose',   ['tapicero']],
        ['ebanisteria',  'Ebanistería',  'Estructura en madera',                   'orange', ['ebanista']],
        ['esqueleteria', 'Esqueletería', 'Armazón y refuerzos',                    'yellow', ['tapicero']],
        ['tapizado',     'Tapizado',     'Telas y relleno',                        'teal',   ['tapicero']],
        ['costura',      'Costura',      'Unión y acabado de telas',               'pink',   ['tapicero']],
        ['laca',         'Laca',         'Acabado con laca',                       'indigo', ['ebanista', 'tapicero']],
        ['pintura',      'Pintura',      'Pintura y acabado final',                'purple', ['ebanista', 'tapicero', 'despachador']],
    ];

    public function up(): void
    {
        Schema::create('tipos_proceso', function (Blueprint $table) {
            $table->id();
            // Lo que queda escrito en cada paso. No cambia aunque se renombre
            // el proceso, para no dejar huérfano el trabajo ya registrado.
            $table->string('clave', 40)->unique();
            $table->string('nombre', 60);
            $table->string('descripcion', 160)->nullable();
            // Nombre de color, no clases: las clases viven en el front, que es
            // quien sabe de Tailwind.
            $table->string('color', 20)->default('slate');
            // Quién lo hace: ebanista, tapicero, despachador
            $table->json('perfiles');
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        foreach (self::INICIALES as $i => [$clave, $nombre, $desc, $color, $perfiles]) {
            DB::table('tipos_proceso')->insert([
                'clave'       => $clave,
                'nombre'      => $nombre,
                'descripcion' => $desc,
                'color'       => $color,
                'perfiles'    => json_encode($perfiles),
                'orden'       => ($i + 1) * 10,
                'activo'      => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        DB::statement('ALTER TABLE produccion_pasos MODIFY tipo_proceso VARCHAR(40) NOT NULL');
    }

    public function down(): void
    {
        $claves = DB::table('tipos_proceso')->pluck('clave')->all();
        $lista  = "'" . implode("','", $claves) . "'";
        DB::statement("ALTER TABLE produccion_pasos MODIFY tipo_proceso ENUM($lista) NOT NULL");
        Schema::dropIfExists('tipos_proceso');
    }
};
