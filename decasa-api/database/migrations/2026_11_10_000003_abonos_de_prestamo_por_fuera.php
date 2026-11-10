<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Abonos a un préstamo por fuera de la nómina.
 *
 * El caso: el trabajador debe $200.000 que se le descuentan de a $50.000 por
 * quincena, y un día 10 llega y paga todo (o una parte) en efectivo. Ese abono
 * es una fila más de `nomina_prestamo_cuotas` (el saldo ya se calcula sumando
 * las filas), pero sin pago de nómina: hay que poder distinguirlo para
 * mostrarlo y para deshacerlo si se anotó mal. Solo agrega columnas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nomina_prestamo_cuotas', function (Blueprint $table) {
            if (! Schema::hasColumn('nomina_prestamo_cuotas', 'tipo')) {
                // null = cuota descontada en la nómina (las de antes) o en la liquidación.
                $table->string('tipo', 20)->nullable()->after('nomina_pago_id');
            }
            if (! Schema::hasColumn('nomina_prestamo_cuotas', 'nota')) {
                $table->string('nota', 160)->nullable()->after('fecha');
            }
            if (! Schema::hasColumn('nomina_prestamo_cuotas', 'registrado_por')) {
                $table->unsignedBigInteger('registrado_por')->nullable()->after('nota');
            }
        });
    }

    public function down(): void
    {
        Schema::table('nomina_prestamo_cuotas', function (Blueprint $table) {
            $table->dropColumn(['tipo', 'nota', 'registrado_por']);
        });
    }
};
