<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Addi como medio de pago.
 *
 * Se registraba como "otro" o como "tarjeta" y no se distinguía. En
 * comisiones cuenta igual que la tarjeta —el intermediario se queda el 5,5%
 * y nadie comisiona sobre eso— y, como la tarjeta, no conserva el descuento
 * de efectivo (ver Orden::METODOS_CON_FRANQUICIA y METODOS_CON_DESCUENTO).
 *
 * Solo en MySQL: es donde la columna es ENUM.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') return;

        DB::statement("ALTER TABLE pagos MODIFY COLUMN metodo ENUM('efectivo','transferencia','tarjeta','addi','otro') NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') return;

        // Lo pagado con Addi vuelve a "tarjeta", que es como cuenta en comisiones.
        DB::table('pagos')->where('metodo', 'addi')->update(['metodo' => 'tarjeta']);
        DB::statement("ALTER TABLE pagos MODIFY COLUMN metodo ENUM('efectivo','transferencia','tarjeta','otro') NULL");
    }
};
