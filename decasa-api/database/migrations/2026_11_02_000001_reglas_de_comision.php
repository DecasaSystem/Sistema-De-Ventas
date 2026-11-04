<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las reglas de comisión con fecha (Fase 1 de docs/plan-comisiones-personalizables.md).
 *
 * Dueño, 2026-10-27: que las reglas de comisión se puedan cambiar sin
 * programar; las cambia solo quien tenga un permiso aparte; por tienda y por
 * persona; hacia un mes pasado solo si ese mes no tiene pagos.
 *
 * - `comision_reglas`: cada cambio es una fila "desde tal mes", para todos,
 *   para una tienda o para una persona. Sin filas, todo se calcula como
 *   siempre (los valores de siempre viven en App\Support\ReglasComision).
 * - `comisiones.reglas_usadas`: la foto de las reglas con que se pagó cada
 *   comisión.
 * - `usuarios.acceso_reglas_comision`: quién puede cambiarlas. Nadie la trae
 *   encendida.
 *
 * Solo crea y agrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('comision_reglas')) {
            Schema::create('comision_reglas', function (Blueprint $t) {
                $t->id();
                $t->string('clave', 50);
                $t->string('alcance', 10);                 // general | tienda | usuario
                $t->unsignedBigInteger('alcance_id')->nullable();
                $t->json('valor')->nullable();              // null = vuelve a la regla de arriba
                $t->char('desde_mes', 7);
                $t->string('nota', 200)->nullable();
                $t->foreignId('creado_por')->nullable()->constrained('usuarios')->nullOnDelete();
                $t->timestamps();
                $t->unique(['clave', 'alcance', 'alcance_id', 'desde_mes'], 'comision_reglas_unica');
                $t->index('desde_mes');
            });
        }

        Schema::table('comisiones', function (Blueprint $t) {
            if (! Schema::hasColumn('comisiones', 'reglas_usadas')) {
                $t->json('reglas_usadas')->nullable();
            }
        });

        Schema::table('usuarios', function (Blueprint $t) {
            if (! Schema::hasColumn('usuarios', 'acceso_reglas_comision')) {
                $t->boolean('acceso_reglas_comision')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', fn (Blueprint $t) => $t->dropColumn('acceso_reglas_comision'));
        Schema::table('comisiones', fn (Blueprint $t) => $t->dropColumn('reglas_usadas'));
        Schema::dropIfExists('comision_reglas');
    }
};
