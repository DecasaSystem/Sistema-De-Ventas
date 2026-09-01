<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién estuvo trabajando en qué tienda, con fechas.
 *
 * Se hacen reemplazos: alguien se va tres días, quince, o un mes a otra
 * tienda a cubrir a quien sale de vacaciones. Mientras está allá vende para
 * esa tienda, y esas ventas empujan la meta de esa tienda y arman su pool.
 * Pero el reparto del pool no lo sabía: el equipo se registraba por MES
 * completo, así que un reemplazo de tres días no se podía expresar, y quien
 * llegaba a reemplazar no entraba al reparto de lo que ayudó a generar.
 *
 * La regla es simple: el reemplazo OCUPA EL PUESTO de alguien. El pool se
 * sigue partiendo entre los mismos —si eran tres, en tres—, y lo que cambia es
 * que la parte del que no estuvo se la lleva quien lo cubrió, por los días que
 * lo cubrió:
 *
 *   - `usuario_id` suma los días que estuvo en `tienda_id`.
 *   - `reemplaza_a_id` pierde esos mismos días: quien está de vacaciones no
 *     cobra los días que no estuvo.
 *   - Y quien se va a cubrir a otra tienda pierde esos días en la suya: una
 *     persona cobra donde estuvo parada vendiendo, no en dos sitios.
 *
 * Por eso a quién se cubre es obligatorio y tiene que ser del equipo de esa
 * tienda: así la suma de las partes no cambia nunca y nadie ve bajar su
 * comisión porque llegó alguien. Quien va a ayudar sin cubrir a nadie no se
 * registra aquí — sus ventas empujan la meta igual y cobra su 5%.
 *
 * Sin ninguna fila aquí, todos los del equipo pesan el mes entero y el
 * reparto da exactamente lo mismo que antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tienda_reemplazos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tienda_id')->constrained('tiendas')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            // A quién cubre. Obligatorio: un reemplazo ocupa el puesto de
            // alguien, no agrega uno más al reparto.
            $table->foreignId('reemplaza_a_id')->constrained('usuarios')->cascadeOnDelete();
            $table->date('desde');
            // Null mientras no se sepa cuándo vuelve: cuenta hasta hoy.
            $table->date('hasta')->nullable();
            $table->string('nota', 200)->nullable();
            $table->timestamps();

            $table->index(['tienda_id', 'desde']);
            $table->index(['usuario_id', 'desde']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tienda_reemplazos');
    }
};
