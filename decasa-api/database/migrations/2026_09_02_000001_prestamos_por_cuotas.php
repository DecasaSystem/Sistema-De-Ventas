<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un préstamo que se descuenta solo, cuota por cuota.
 *
 * Hasta ahora un préstamo se manejaba como un "ajuste": un descuento suelto
 * que había que crear a mano cada vez que se pagaba. Para $200.000 a cuatro
 * quincenas eso son cuatro ajustes escritos de memoria, y basta olvidar uno
 * para que la plata se pierda.
 *
 * Ahora se registra una vez —cuánto y en cuántas cuotas— y el sistema
 * descuenta una cuota en cada pago hasta saldarlo. El saldo se calcula
 * sumando lo ya descontado, no se guarda: un saldo guardado se desincroniza
 * en cuanto se anula un pago.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nomina_prestamos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->string('motivo', 160)->nullable();
            $table->decimal('monto', 12, 2);            // lo que se prestó
            $table->unsignedSmallInteger('cuotas');     // en cuántos pagos
            $table->decimal('valor_cuota', 12, 2);      // cuánto por pago
            $table->date('fecha');                      // desde cuándo se descuenta
            $table->foreignId('creado_por')->nullable()->constrained('usuarios');
            // Se puede pausar sin borrarlo: si alguien está en licencia, no se
            // le descuenta, pero la deuda sigue ahí.
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index(['usuario_id', 'activo']);
        });

        // Cada cuota descontada queda como una fila: es lo que permite saber
        // el saldo y deshacerlo si se anula el pago.
        Schema::create('nomina_prestamo_cuotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prestamo_id')->constrained('nomina_prestamos')->cascadeOnDelete();
            $table->foreignId('nomina_pago_id')->nullable()->constrained('nomina_pagos')->nullOnDelete();
            $table->decimal('monto', 12, 2);
            $table->date('fecha');
            $table->timestamps();

            $table->index('prestamo_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nomina_prestamo_cuotas');
        Schema::dropIfExists('nomina_prestamos');
    }
};
