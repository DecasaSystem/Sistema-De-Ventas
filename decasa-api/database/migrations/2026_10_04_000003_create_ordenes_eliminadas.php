<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Constancia de las órdenes que un supervisor borró.
 *
 * Una venta borrada desaparece de todas partes —reportes, comisiones, caja—,
 * así que tiene que quedar dónde se diga quién la borró, cuándo, por qué y
 * cómo estaba (con sus ítems y pagos en `datos`). Sin llaves a otras tablas a
 * propósito: la orden ya no existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordenes_eliminadas', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('orden_id');
            $t->string('referencia', 40)->nullable();
            $t->string('cliente_nombre', 160)->nullable();
            $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->unsignedBigInteger('tienda_id')->nullable();
            $t->string('estado', 40)->nullable();
            $t->decimal('valor_total', 15, 2)->default(0);
            $t->decimal('pagado', 15, 2)->default(0);
            $t->string('numeracion', 10);          // 'hueco' | 'correr'
            $t->json('corridas')->nullable();       // las que bajaron un número
            $t->string('motivo', 500);
            $t->unsignedBigInteger('eliminada_por_id');
            $t->json('datos')->nullable();          // la orden como estaba, con ítems y pagos
            $t->timestamps();

            $t->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordenes_eliminadas');
    }
};
