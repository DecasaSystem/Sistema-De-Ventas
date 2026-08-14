<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los proveedores del negocio: quién es, cómo se le contacta y qué provee.
 *
 * Es una libreta compartida, no un módulo con lógica de negocio: cualquiera
 * la lee y cualquiera puede sumarle uno nuevo, como pedirlo el usuario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedores', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('contacto')->nullable();   // la persona con quien se habla
            $table->string('telefono')->nullable();
            $table->text('productos')->nullable();     // qué provee, en texto libre
            $table->string('direccion')->nullable();
            $table->text('notas')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        // Los datos con los que arranca el módulo.
        $ahora = now();
        DB::table('proveedores')->insert(array_map(fn (array $p) => $p + [
            'direccion' => null, 'notas' => null, 'activo' => true,
            'created_at' => $ahora, 'updated_at' => $ahora,
        ], [
            ['nombre' => 'Espuma Santa Fé',          'contacto' => 'Silvio',   'telefono' => '3158937683', 'productos' => 'Espuma'],
            ['nombre' => 'Texti Muebles',            'contacto' => 'David',    'telefono' => '3153312281', 'productos' => 'Herrajes, tela, pegantes, espumas, grapa, sincha, tafeta, cambre'],
            ['nombre' => 'Pegantes Afix',            'contacto' => 'Neftalí',  'telefono' => '3146274491', 'productos' => 'Pegante'],
            ['nombre' => 'Tauro Moda',               'contacto' => null,       'telefono' => '3137217112', 'productos' => 'Herrajes, tela, pegantes, espumas, grapa, sincha, tafeta, cambre'],
            ['nombre' => 'Jova',                     'contacto' => null,       'telefono' => '3152009686', 'productos' => 'Herrajes, tela, pegantes, espumas, grapa, sincha, tafeta, cambre'],
            ['nombre' => 'Peletería El Búfalo',      'contacto' => null,       'telefono' => '3104011985', 'productos' => 'Cremalleras, accesorios de cremalleras, cordel'],
            ['nombre' => 'Ferre Agro',               'contacto' => null,       'telefono' => '3008600404', 'productos' => 'Ferretería'],
            ['nombre' => 'Ferre Eléctricos Restrepo','contacto' => null,       'telefono' => null,         'productos' => 'Ferretería'],
            ['nombre' => 'El Carpintero',            'contacto' => null,       'telefono' => null,         'productos' => 'Fresas para máquina, tornillos, rieles, rodachines'],
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('proveedores');
    }
};
