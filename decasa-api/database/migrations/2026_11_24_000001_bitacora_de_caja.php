<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La caja no es una lista guardada: el saldo se vuelve a sumar con los pagos en
 * efectivo de cada momento. Corregir un abono de efectivo a transferencia,
 * bajarle el monto o borrar la orden cambiaba el saldo sin dejar nada en la
 * caja (usuario, 2026-11-23: "se restó y ni siquiera se sabe por qué").
 *
 * - `caja_bitacora`: un renglón por cada cambio de un pago que mueve una caja.
 *   Solo explica: el saldo ya lo refleja (no se suma otra vez).
 * - `caja_movimientos.anulado_*`: un ingreso o egreso manual ya no se borra;
 *   se anula, deja de sumar y se sigue viendo con quién y por qué.
 *
 * Solo agrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('caja_bitacora')) {
            Schema::create('caja_bitacora', function (Blueprint $t) {
                $t->id();
                // La caja que cambió: la de una tienda o la propia de quien
                // vende por su cuenta (independientes). Una de las dos.
                $t->unsignedBigInteger('tienda_id')->nullable();
                $t->unsignedBigInteger('caja_usuario_id')->nullable();
                // Sin llave: el pago o la orden pueden haberse borrado (es
                // justo lo que se anota) y el renglón tiene que quedar.
                $t->unsignedBigInteger('pago_id')->nullable();
                $t->unsignedBigInteger('orden_id')->nullable();
                $t->string('referencia', 40)->nullable();
                $t->unsignedBigInteger('usuario_id')->nullable();   // quién hizo el cambio
                $t->string('motivo', 30);                            // correccion | borrado
                $t->decimal('efecto', 12, 2);                        // cuánto subió (+) o bajó (−) la caja
                $t->string('concepto', 255);
                $t->text('descripcion')->nullable();
                $t->timestamps();

                $t->index(['tienda_id', 'created_at'], 'idx_caja_bitacora_tienda');
                $t->index(['caja_usuario_id', 'created_at'], 'idx_caja_bitacora_usuario');
            });
        }

        Schema::table('caja_movimientos', function (Blueprint $t) {
            if (! Schema::hasColumn('caja_movimientos', 'anulado_at')) {
                $t->timestamp('anulado_at')->nullable();
            }
            if (! Schema::hasColumn('caja_movimientos', 'anulado_por_id')) {
                $t->unsignedBigInteger('anulado_por_id')->nullable();
            }
            if (! Schema::hasColumn('caja_movimientos', 'motivo_anulacion')) {
                $t->string('motivo_anulacion', 500)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caja_bitacora');
        Schema::table('caja_movimientos', function (Blueprint $t) {
            foreach (['anulado_at', 'anulado_por_id', 'motivo_anulacion'] as $c) {
                if (Schema::hasColumn('caja_movimientos', $c)) $t->dropColumn($c);
            }
        });
    }
};
