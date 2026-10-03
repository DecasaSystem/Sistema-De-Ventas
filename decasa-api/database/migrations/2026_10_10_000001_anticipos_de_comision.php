<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anticipos de comisión.
 *
 * Algunos vendedores (sobre todo los de Pereira) se llevan cada mes una parte
 * de su comisión por adelantado —por ejemplo $200.000— y cuando se les paga
 * la comisión se les descuenta lo que ya se llevaron. Si no comisionan, lo
 * quedan debiendo y se descuenta del siguiente pago.
 *
 *  - `comision_anticipos_config`: si un vendedor tiene anticipo y de cuánto,
 *    vigente desde un mes. Cambiarlo vale de ese mes en adelante; los meses
 *    anteriores se quedan como estaban.
 *  - `comision_anticipos`: el libro. Cada mes un renglón `anticipo` (lo que
 *    se llevó; editable si ese mes tomó otra cantidad) y, al pagar
 *    comisiones, renglones `descuento` atados a la comisión de la que se
 *    descontó (si el pago se deshace, el descuento se devuelve).
 *
 * Lo que debe = anticipos hasta el mes que se paga − lo ya descontado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comision_anticipos_config', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vendedor_id')->constrained('usuarios');
            $t->char('desde_mes', 7);
            $t->decimal('monto', 15, 2)->default(0);
            $t->boolean('activo')->default(true);
            $t->foreignId('creado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $t->timestamps();
            $t->unique(['vendedor_id', 'desde_mes']);
        });

        Schema::create('comision_anticipos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vendedor_id')->constrained('usuarios');
            $t->string('tipo', 12);                 // anticipo | descuento
            $t->char('mes', 7);                     // anticipo: el mes en que se lo llevó; descuento: el mes de la comisión
            $t->decimal('monto', 15, 2);            // siempre positivo
            // Un solo anticipo por persona y mes (la base lo garantiza).
            $t->string('clave', 40)->nullable()->unique();
            // El descuento sale de una comisión concreta: si su pago se
            // deshace, el descuento se devuelve con ella.
            $t->foreignId('comision_id')->nullable()->constrained('comisiones')->cascadeOnDelete();
            $t->boolean('editado_a_mano')->default(false);
            $t->string('nota', 200)->nullable();
            $t->foreignId('creado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $t->timestamps();
            $t->index(['vendedor_id', 'tipo', 'mes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comision_anticipos');
        Schema::dropIfExists('comision_anticipos_config');
    }
};
