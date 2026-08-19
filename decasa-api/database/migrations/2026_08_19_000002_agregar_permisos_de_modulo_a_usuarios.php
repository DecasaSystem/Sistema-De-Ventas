<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Antes, un supervisor traía Costos/Despacho/Métricas/Producción/Reserva/
 * Comisiones/Surtir automáticamente por ser supervisor. Eso hacía imposible
 * dar de alta a alguien como supervisor (por ejemplo, solo por el esquema de
 * comisiones de tiendas sin meta) sin regalarle de paso todos esos módulos.
 * Cada uno pasa a ser una bandera asignable por trabajador, igual que
 * acceso_surtir.
 *
 * A nadie se le quita nada el día que esto sale: se deja encendido a todo
 * supervisor y vendedor activo que ya usa cada módulo hoy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->boolean('acceso_costos')->default(false)->after('acceso_surtir');
            $table->boolean('acceso_proveedores')->default(false)->after('acceso_costos');
            $table->boolean('acceso_despacho')->default(false)->after('acceso_proveedores');
            $table->boolean('acceso_metricas')->default(false)->after('acceso_despacho');
            $table->boolean('acceso_produccion')->default(false)->after('acceso_metricas');
            $table->boolean('acceso_reserva')->default(false)->after('acceso_produccion');
            $table->boolean('ve_todas_ordenes')->default(false)->after('acceso_reserva');
        });

        // Costos: hoy es supervisor,ebanista. El ebanista sigue automático
        // (no se toca), así que aquí solo se respalda al supervisor.
        DB::table('usuarios')->where('rol', 'supervisor')->update(['acceso_costos' => true]);

        // Proveedores: el módulo nació abierto a cualquiera. Se respalda a
        // todos los que ya podían crear/editar proveedores.
        DB::table('usuarios')->whereIn('rol', ['supervisor', 'vendedor'])->update(['acceso_proveedores' => true]);

        // Despacho, Métricas, Producción: eran automáticos para todo supervisor.
        DB::table('usuarios')->where('rol', 'supervisor')->update([
            'acceso_despacho'   => true,
            'acceso_metricas'   => true,
            'acceso_produccion' => true,
        ]);

        // Reserva/Fábrica: era automática para vendedor y supervisor.
        DB::table('usuarios')->whereIn('rol', ['supervisor', 'vendedor'])->update(['acceso_reserva' => true]);

        // Surtir ya existía, pero solo se había respaldado a los vendedores.
        // El acceso automático de "isSupervisor ||" se quita del código, así
        // que ahora también hay que respaldar al supervisor.
        DB::table('usuarios')->where('rol', 'supervisor')->update(['acceso_surtir' => true]);
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn([
                'acceso_costos',
                'acceso_proveedores',
                'acceso_despacho',
                'acceso_metricas',
                'acceso_produccion',
                'acceso_reserva',
                've_todas_ordenes',
            ]);
        });
    }
};
