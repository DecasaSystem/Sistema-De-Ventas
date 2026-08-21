<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compras: la lista de "hay que comprar tal cosa", compartida por todos.
 *
 * Nace pendiente con solo lo que se necesita (qué, cuánto, notas). Cuando
 * alguien sale a comprarlo, esa misma fila se completa con quién compró,
 * cuánto costó, cuándo y la foto de la factura — y pasa a ser historial. No
 * hay una tabla aparte para "lo comprado": es la misma fila que cambia de
 * estado, así que no se puede perder la relación entre lo que se pidió y lo
 * que realmente se compró.
 *
 * `comprador_nombre` es texto libre y no un usuario del sistema: quien sale
 * a comprar (un ebanista, alguien del taller) muchas veces no tiene cuenta
 * en la app, igual que los `empleados` de Nómina. `registrado_por_id` sí es
 * quien usó el sistema para dejarlo asentado, que puede ser otra persona.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compras', function (Blueprint $table) {
            $table->id();
            $table->string('item', 150);
            // Texto libre y no numérico: "4", "2 cajas", "1 rollo de 50m".
            $table->string('cantidad', 50)->nullable();
            $table->text('notas')->nullable();
            $table->foreignId('solicitado_por_id')->constrained('usuarios');

            $table->enum('estado', ['pendiente', 'comprado'])->default('pendiente');

            $table->string('comprador_nombre', 120)->nullable();
            $table->decimal('precio', 12, 2)->nullable();
            $table->date('fecha_compra')->nullable();
            $table->string('factura_foto_url', 500)->nullable();
            $table->foreignId('registrado_por_id')->nullable()->constrained('usuarios');

            $table->timestamps();
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compras');
    }
};
