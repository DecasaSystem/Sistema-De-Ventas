<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Garantías: lo que se daña DESPUÉS de entregado.
 *
 * El caso: a la semana de recibir la sala, al cliente se le hunde un cojín o se
 * le despega una pata. Hasta ahora no había por dónde: una orden entregada no
 * dejaba reportar nada, y lo único posible era "cambiar producto" (solo
 * supervisor, solo por otro producto). Las devoluciones son para lo que vuelve
 * en el camión o se daña antes de salir, y aplicadas a algo entregado
 * descontaban el stock dos veces y dejaban la pieza sin poderse volver a
 * entregar.
 *
 * Una garantía cuelga del producto de la orden (no de la orden entera: de la
 * sala y las dos mesas, se dañó la sala) y termina de una de cuatro maneras,
 * que son las del anexo de garantías que firma el cliente:
 *
 *   taller      → se recoge, el taller lo arregla con sus pasos y se le vuelve
 *                 a entregar con acta. El producto se "reactiva" en la orden.
 *   domicilio   → alguien va a la casa y lo arregla allá. Queda quién fue,
 *                 cuándo y qué hizo.
 *   cambio      → por otra unidad igual o por otro producto, consultando el
 *                 inventario de verdad (o mandándolo a fabricar si no hay).
 *   reembolso   → se le devuelve la plata y el cliente devuelve el producto.
 *                 La plata sale cuando el producto llega (un pago negativo
 *                 tipo `reembolso`), y lo devuelto deja de ser venta.
 *   no_procede  → vencida o excluida por el anexo (sol, humedad, golpe...),
 *                 con la causal escrita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('garantias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_id')->constrained('ordenes')->cascadeOnDelete();
            $table->foreignId('orden_item_id')->constrained('orden_items')->cascadeOnDelete();
            // De cuatro sillas se puede dañar una.
            $table->unsignedInteger('cantidad')->default(1);

            // De qué es el daño: decide cuánto dura la garantía (anexo,
            // numeral 3). La línea solo importa en la madera: 5 años élite y
            // promocional, 2 la económica. Se escoge al reportar porque los
            // productos no la tienen.
            $table->enum('tipo_dano', ['madera', 'tela_espuma', 'otro']);
            $table->enum('linea', ['elite_promocional', 'economica'])->nullable();

            $table->text('motivo');
            $table->json('fotos')->nullable();
            // Dónde está el mueble cuando se reporta: si está en la casa, antes
            // de arreglarlo en el taller hay que recogerlo.
            $table->enum('donde_esta', ['casa_cliente', 'tienda'])->default('casa_cliente');
            // Lo que el cliente pide. Es una sugerencia para quien decide.
            $table->enum('preferencia_cliente', ['arreglar', 'cambiar_mismo', 'cambiar_otro', 'reembolso'])->nullable();

            $table->date('fecha_reporte');
            // Desde cuándo corre la garantía (la última entrega de ese
            // producto) y hasta cuándo va. Se guardan para que no cambien si
            // después alguien toca las entregas.
            $table->date('fecha_entrega')->nullable();
            $table->date('vence_el')->nullable();
            // Ley 1480, art. 58: la reclamación se responde en 15 días hábiles.
            $table->date('responder_antes_de')->nullable();
            $table->foreignId('reportado_por_id')->nullable()->constrained('usuarios');

            // pendiente → esperando dictamen.
            // por_recoger → se arregla en el taller, pero el mueble sigue en la casa.
            // en_taller → el taller lo tiene; se cierra al volverlo a entregar.
            // a_domicilio → alguien va a arreglarlo a la casa.
            // cambio → se cambia; se cierra al entregar el reemplazo.
            // resuelta / no_procede → cerrada.
            // por_devolver → se aprobó devolverle la plata; espera el producto.
            $table->enum('estado', ['pendiente', 'por_recoger', 'en_taller', 'a_domicilio', 'cambio', 'por_devolver', 'resuelta', 'no_procede'])
                ->default('pendiente');
            $table->enum('decision', ['taller', 'domicilio', 'cambio_mismo', 'cambio_otro', 'reembolso', 'no_procede'])->nullable();
            $table->foreignId('decidido_por_id')->nullable()->constrained('usuarios');
            $table->timestamp('decidido_at')->nullable();
            $table->text('notas_decision')->nullable();
            // Por qué no procede: el numeral del anexo o "vencida".
            $table->string('causal_no_procede', 40)->nullable();

            // Taller: los procesos que se rehacen (se crean al recibir el
            // mueble) y cuándo llegó, que es desde donde corren los 30 días
            // que promete el anexo para devolverlo.
            $table->json('procesos_reparacion')->nullable();
            $table->foreignId('produccion_id')->nullable()->constrained('produccion')->nullOnDelete();
            $table->timestamp('recibido_en_taller_at')->nullable();
            $table->foreignId('recibido_por_id')->nullable()->constrained('usuarios');

            // Domicilio: quién va, cuándo, y lo que encontró e hizo.
            $table->foreignId('visita_por_id')->nullable()->constrained('usuarios');
            $table->date('visita_fecha')->nullable();
            $table->text('visita_notas')->nullable();
            $table->json('visita_fotos')->nullable();

            // Cambio: el renglón nuevo de la orden con el reemplazo, y lo que
            // se cobró por la diferencia (lo decide quien aprueba).
            $table->foreignId('orden_item_nuevo_id')->nullable()->constrained('orden_items')->nullOnDelete();
            $table->decimal('diferencia_valor', 15, 2)->nullable();

            // Reembolso: lo aprobado, el pago (negativo) con que salió la plata
            // y qué se hizo con lo que devolvió el cliente.
            $table->decimal('monto_reembolso', 15, 2)->nullable();
            $table->foreignId('pago_reembolso_id')->nullable()->constrained('pagos')->nullOnDelete();
            $table->enum('destino_devuelto', ['inventario', 'merma'])->nullable();
            $table->foreignId('tienda_devuelto_id')->nullable()->constrained('tiendas')->nullOnDelete();

            // Con qué entrega se cerró (taller y cambio).
            $table->foreignId('despacho_item_id')->nullable()->constrained('despacho_items')->nullOnDelete();
            $table->timestamp('resuelta_at')->nullable();
            $table->foreignId('resuelta_por_id')->nullable()->constrained('usuarios');

            $table->timestamps();

            $table->index(['estado', 'fecha_reporte']);
            $table->index('orden_id');
        });

        // Unidades que vuelven a estar "por entregar" porque se están
        // arreglando, pero que ya salieron del inventario la primera vez: al
        // volverlas a entregar no se descuenta stock otra vez.
        Schema::table('orden_items', function (Blueprint $table) {
            if (! Schema::hasColumn('orden_items', 'cantidad_en_garantia')) {
                $table->unsignedInteger('cantidad_en_garantia')->default(0)->after('cantidad_entregada');
            }
        });

        // Cuántas de las unidades de esa línea fueron una pieza reparada que
        // se devolvió al cliente: así deshacer la entrega no le suma al
        // inventario algo que nunca le restó.
        Schema::table('entrega_lineas', function (Blueprint $table) {
            if (! Schema::hasColumn('entrega_lineas', 'unidades_garantia')) {
                $table->unsignedInteger('unidades_garantia')->default(0)->after('cantidad');
            }
        });

        // El reembolso de una garantía es un pago NEGATIVO de tipo `reembolso`:
        // así lo pagado neto, el saldo, la caja (si salió en efectivo), la
        // cartera y el 50 % de las comisiones lo cuentan solos, sin tocar los
        // sitios que suman pagos. La lista sale de la tabla real y no de las
        // migraciones, por si en producción el enum trae algo agregado por
        // fuera (ya pasó con `ordenes.estado`).
        if (DB::getDriverName() === 'mysql') {
            $col = DB::selectOne("SHOW COLUMNS FROM pagos LIKE 'tipo'");
            if ($col && preg_match_all("/'([^']*)'/", $col->Type, $m) && ! in_array('reembolso', $m[1], true)) {
                $valores = implode(',', array_map(fn ($v) => "'" . $v . "'", [...$m[1], 'reembolso']));
                DB::statement("ALTER TABLE pagos MODIFY COLUMN tipo ENUM({$valores}) NOT NULL");
            }
        }

        // Los pasos que se hicieron por una garantía: la historia del mueble
        // dice qué fue fabricación y qué fue arreglo.
        Schema::table('produccion_pasos', function (Blueprint $table) {
            if (! Schema::hasColumn('produccion_pasos', 'garantia_id')) {
                $table->foreignId('garantia_id')->nullable()->constrained('garantias')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('produccion_pasos', function (Blueprint $table) {
            if (Schema::hasColumn('produccion_pasos', 'garantia_id')) {
                $table->dropConstrainedForeignId('garantia_id');
            }
        });
        Schema::table('entrega_lineas', function (Blueprint $table) {
            if (Schema::hasColumn('entrega_lineas', 'unidades_garantia')) {
                $table->dropColumn('unidades_garantia');
            }
        });
        Schema::table('orden_items', function (Blueprint $table) {
            if (Schema::hasColumn('orden_items', 'cantidad_en_garantia')) {
                $table->dropColumn('cantidad_en_garantia');
            }
        });
        Schema::dropIfExists('garantias');
    }
};
