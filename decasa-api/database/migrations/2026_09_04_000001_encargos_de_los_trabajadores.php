<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De qué está encargado cada trabajador: el portátil, la pantalla, los dos
 * taladros, el martillo.
 *
 * Hoy eso no está en ninguna parte: se entrega una herramienta y la única
 * constancia es que alguien se acuerde. Cuando la persona se va —o cuando
 * simplemente no aparece el taladro— no hay cómo saber quién lo tenía ni
 * desde cuándo.
 *
 * El módulo se prende por trabajador (`lleva_encargos`), no para toda la
 * empresa: a la mayoría no se le entrega nada y llenarles la ficha de una
 * sección vacía solo estorba. Va aparte del permiso para administrarlo
 * (`acceso_encargos`), porque son dos cosas distintas: Adrián responde por
 * dos taladros pero no tiene por qué ver los de nadie más — de hecho puede
 * ni entrar al programa.
 *
 * Cada cierto tiempo se le pasa revista a cada uno y se va marcando qué está
 * bien, qué se dañó y qué se perdió. Eso es una `encargo_revision` con sus
 * items, y es lo que deja el rastro: no se guarda solo el estado actual, se
 * guarda cada revista, para poder mirar atrás y ver cuándo se perdió algo y
 * quién lo revisó ese día.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            // Se le entregan cosas: aparece en Encargos y se le hace revista.
            // Sirve para cualquiera, use o no el programa — el del taladro
            // normalmente es de fábrica y no tiene cuenta.
            $table->boolean('lleva_encargos')->default(false);
            // Administra el módulo: ve a todos, entrega y hace las revistas.
            // Esto sí es del programa, como los demás acceso_*.
            $table->boolean('acceso_encargos')->default(false);
            // Cada cuántos días se le pasa revista a esta persona. En null usa
            // el número general (`encargos_dias_revision` en `configuracion`),
            // que es lo normal; se pone a mano solo para el que necesita otro
            // ritmo —al del portátil se le puede mirar cada seis meses y al
            // del taller cada mes—.
            $table->unsignedSmallInteger('encargo_revision_dias')->nullable();
        });

        // Cada cosa entregada. Una fila por artículo, con su cantidad: "2
        // taladros" es una fila con cantidad 2, no dos filas — así se entrega
        // y se cuenta igual que se habla en el taller.
        Schema::create('encargos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->string('nombre', 150);                 // "Taladro Bosch", "Portátil HP"
            $table->unsignedInteger('cantidad')->default(1);
            // Cuánto se dañó de esa cantidad. No es historia: es el estado de
            // hoy, y lo reescribe cada revisión. Lo que pasó antes queda en
            // los items de cada revista.
            $table->unsignedInteger('cantidad_danada')->default(0);
            // Serial, placa o número de inventario. Lo que de verdad
            // identifica al portátil cuando hay cinco iguales.
            $table->string('serial', 80)->nullable();
            // Lo que cuesta reponer UNA. Es la base del descuento cuando se
            // pierde; puede quedar vacío si es algo que no se le cobra.
            $table->decimal('valor_unitario', 12, 2)->nullable();
            $table->date('fecha_entrega');
            $table->string('foto_url', 500)->nullable();
            $table->text('notas')->nullable();
            // a_cargo: lo tiene. devuelto: lo entregó de vuelta.
            // perdido: se perdió todo. baja: se acabó su vida útil, no se cobra.
            $table->enum('estado', ['a_cargo', 'devuelto', 'perdido', 'baja'])->default('a_cargo');
            $table->date('cerrado_en')->nullable();        // cuándo dejó de estar a cargo
            $table->foreignId('entregado_por_id')->nullable()->constrained('usuarios');
            $table->timestamps();

            $table->index(['usuario_id', 'estado']);
        });

        // Una revista: el día que alguien se sentó con el trabajador a contar
        // lo que tiene. Se guarda completa de una sola vez.
        Schema::create('encargo_revisiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->foreignId('revisado_por_id')->nullable()->constrained('usuarios');
            $table->date('fecha');
            $table->text('notas')->nullable();
            // El descuento que salió de esta revista, si se decidió cobrarlo.
            // Va contra Nómina como un ajuste negativo; se guarda el id para
            // poder llegar de la revista al descuento y al revés.
            $table->decimal('descuento_total', 12, 2)->default(0);
            $table->foreignId('nomina_ajuste_id')->nullable()
                  ->constrained('nomina_ajustes')->nullOnDelete();
            $table->timestamps();

            $table->index(['usuario_id', 'fecha']);
        });

        // Qué se encontró de cada cosa ese día. Las tres cantidades suman lo
        // que había: se cuenta pieza por pieza, no "bien / mal".
        Schema::create('encargo_revision_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('revision_id')->constrained('encargo_revisiones')->cascadeOnDelete();
            // El encargo se conserva aunque la persona ya no lo tenga, así que
            // no hace falta copiar el nombre acá.
            $table->foreignId('encargo_id')->constrained('encargos')->cascadeOnDelete();
            $table->unsignedInteger('cantidad_ok')->default(0);
            $table->unsignedInteger('cantidad_danada')->default(0);
            $table->unsignedInteger('cantidad_perdida')->default(0);
            // Lo que se le cobra por lo perdido de ESTA línea. Se calcula
            // sugiriendo perdidas × valor_unitario, pero queda editable: a
            // veces se perdona, a veces se cobra a medias.
            $table->decimal('descuento', 12, 2)->default(0);
            $table->string('notas', 300)->nullable();
            $table->timestamps();

            // Una sola línea por cosa en cada revista: contarla dos veces el
            // mismo día descuadraría el conteo.
            $table->unique(['revision_id', 'encargo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encargo_revision_items');
        Schema::dropIfExists('encargo_revisiones');
        Schema::dropIfExists('encargos');

        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn(['lleva_encargos', 'acceso_encargos', 'encargo_revision_dias']);
        });
    }
};
