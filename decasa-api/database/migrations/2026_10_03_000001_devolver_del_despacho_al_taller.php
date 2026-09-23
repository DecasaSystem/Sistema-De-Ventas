<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que ya salió del taller y todavía no se entregó, puede volver.
 *
 * Pasa todo el tiempo: la pieza está en la bodega esperando el camión y
 * alguien ve que le falta una manija, que la tela no era esa, o que se rayó
 * moviéndola. Hasta hoy había dos salidas y ninguna servía:
 *
 *  - "Devolver paso anterior" (ProduccionController::devolverPaso) necesita un
 *    paso EN CURSO desde el cual devolver, y una pieza despachada no tiene
 *    ninguno: todos sus pasos están completados.
 *  - Devolverla como `Devolucion` → "a produccion" la deja en `pendiente`, es
 *    decir, con el flujo de pasos borrado y por armar de nuevo: el mueble
 *    vuelve a pasar por ebanistería, tapizado y laca para pegarle una manija.
 *
 * Esta tabla es el rastro del camino que faltaba: la pieza vuelve al taller,
 * quien la devuelve dice A QUÉ PASO vuelve y CUÁLES pasos hay que rehacer, y
 * los que no se marcan se quedan completados — no se hacen de nuevo.
 *
 * Es una tabla y no dos columnas en `produccion` porque la misma pieza puede
 * volver varias veces, y cuántas veces volvió (y por qué) es justo lo que hay
 * que poder mirar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produccion_retornos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('produccion_id')->constrained('produccion');
            // El paso donde se retoma el trabajo: queda `en_proceso`.
            $table->foreignId('paso_destino_id')->constrained('produccion_pasos');
            // Los ids de los pasos que se rehacen, destino incluido. Lo que no
            // esté aquí sigue completado y el flujo lo salta.
            $table->json('pasos_rehacer');
            $table->text('motivo');
            $table->string('foto_url', 500)->nullable();
            // De qué entrega se bajó, cuando ya estaba subida a una ruta.
            $table->unsignedBigInteger('despacho_item_id')->nullable();
            $table->foreignId('devuelto_por_id')->constrained('usuarios');
            $table->timestamp('created_at')->useCurrent();
            // Cuándo volvió a quedar lista. Abierto = sigue en el taller.
            $table->timestamp('resuelto_at')->nullable();

            $table->index(['produccion_id', 'resuelto_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produccion_retornos');
    }
};
