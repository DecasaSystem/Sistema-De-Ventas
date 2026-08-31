<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que el asesor copia y pega mientras atiende: direcciones, horarios,
 * formas de pago, enlaces.
 *
 * Estaba escrito dentro de la pantalla —las cinco sedes de Armenia y Pereira,
 * "envío gratis en el Quindío"— así que sólo servía para esta empresa. Ahora
 * es una lista que cada negocio arma como quiera.
 *
 * El `tipo` no es decoración: dice qué se puede hacer con el contenido.
 *   texto     → se copia tal cual (un horario, una política de pagos)
 *   direccion → se copia y además se abre en el mapa
 *   enlace    → se copia y además se abre en otra pestaña
 *
 * La `seccion` es texto libre a propósito: agrupar es cosa de cada empresa, y
 * una lista cerrada de secciones sería otra cosa más que habría que venir a
 * cambiar en el código.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('herramientas', function (Blueprint $table) {
            $table->id();
            $table->string('seccion', 60)->default('General');
            $table->string('titulo', 120);
            $table->string('tipo', 20)->default('texto');   // texto | direccion | enlace
            $table->text('contenido');
            $table->string('subtitulo', 200)->nullable();   // la línea pequeña bajo el título
            $table->string('icono', 60)->nullable();
            $table->boolean('activo')->default(true);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });

        $ahora  = now();
        $filas  = [];
        $orden  = 0;

        // Las sedes que estaban escritas en la pantalla.
        foreach ([
            ['Av. Bolívar',        'Avenida Bolívar # 16 N 26, Armenia, Quindío'],
            ['Vía El Edén',        'Km 2 vía El Edén, Armenia, Quindío'],
            ['Vía Jardines',       'Km 1 vía Jardines, Armenia, Quindío'],
            ['Unicentro Pereira',  'C.C. Unicentro, Pereira, Risaralda'],
            ['Cra. 14 Pereira',    'Cra. 14 #11-93, Pereira, Risaralda'],
        ] as [$nombre, $direccion]) {
            $filas[] = [
                'seccion' => 'Sedes', 'titulo' => $nombre, 'tipo' => 'direccion',
                'contenido' => $direccion, 'subtitulo' => null, 'icono' => 'MapPinIcon',
                'activo' => true, 'orden' => $orden += 10,
                'created_at' => $ahora, 'updated_at' => $ahora,
            ];
        }

        $orden = 0;
        foreach ([
            ['Horario de atención', 'ClockIcon',
             "Nuestro horario de atención es:\nLunes a Viernes: 8:00 am – 5:00 pm\nSábados: 8:00 am – 12:00 pm 😊"],
            ['Formas de pago', 'CreditCardIcon',
             "Formas de pago: efectivo, transferencia bancaria, tarjeta de crédito/débito y ADDI (crédito) 💳\nLos descuentos aplican solo con pago en efectivo o transferencia."],
            ['Envíos', 'TruckIcon',
             "Envío GRATIS en todo el Quindío y en Pereira (Risaralda) 🚚\nPara destinos fuera de esas zonas hay un costo adicional de transportadora — con gusto te lo cotizamos."],
        ] as [$titulo, $icono, $texto]) {
            $filas[] = [
                'seccion' => 'Textos rápidos', 'titulo' => $titulo, 'tipo' => 'texto',
                'contenido' => $texto, 'subtitulo' => null, 'icono' => $icono,
                'activo' => true, 'orden' => $orden += 10,
                'created_at' => $ahora, 'updated_at' => $ahora,
            ];
        }

        DB::table('herramientas')->insert($filas);
    }

    public function down(): void
    {
        Schema::dropIfExists('herramientas');
    }
};
