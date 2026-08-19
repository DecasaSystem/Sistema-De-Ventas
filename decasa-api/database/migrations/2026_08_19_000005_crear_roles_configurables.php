<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Rol" pasa de un enum fijo en código a una tabla configurable, para que
 * una empresa nueva pueda crear sus propios puestos (nombre libre) sin
 * pedirle a un desarrollador que le agregue el rol al código.
 *
 * Cada fila igual se apoya en uno de 5 "arquetipos" fijos (el comportamiento
 * de fondo que ya existe: vendedor, supervisor, conductor, taller,
 * despachador) — eso es lo que sigue determinando cosas serias como el
 * cálculo de comisiones o si alguien lleva caja propia, y NO se toca en
 * esta migración.
 *
 * Compatibilidad: la columna `usuarios.rol` NO se renombra ni se borra —
 * sigue siendo un string real, sincronizado automáticamente con la `clave`
 * del rol asignado (ver el hook en Usuario::booted()). Para los 6 roles de
 * siempre esa clave es idéntica al string que ya usaban ('vendedor',
 * 'ebanista', etc.), así que los ~40 sitios del backend que hoy hacen
 * `$usuario->rol === 'ebanista'` o `where('rol', 'ebanista')` —da igual si
 * es en PHP o en una consulta SQL— siguen funcionando exactamente igual,
 * sin tocarlos. Pasa de ENUM a VARCHAR para admitir claves nuevas que una
 * empresa invente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 60)->unique();
            $table->string('nombre', 80);
            $table->enum('arquetipo', ['vendedor', 'supervisor', 'conductor', 'taller', 'despachador']);
            $table->boolean('activo')->default(true);
            $table->unsignedInteger('orden')->default(0);
            // Banderas de plantilla: solo sugieren los checkboxes por defecto
            // al asignar el rol a alguien nuevo. Cada persona conserva su
            // propio ajuste fino después, igual que ya funciona hoy.
            $table->boolean('acceso_redes')->default(false);
            $table->boolean('acceso_comisiones')->default(false);
            $table->boolean('recarga_telas')->default(false);
            $table->boolean('acceso_surtir')->default(false);
            $table->boolean('acceso_costos')->default(false);
            $table->boolean('acceso_proveedores')->default(false);
            $table->boolean('acceso_despacho')->default(false);
            $table->boolean('acceso_produccion')->default(false);
            $table->boolean('acceso_reserva')->default(false);
            $table->timestamps();
        });

        $ahora = now();
        $flagsDefault = [
            'acceso_redes' => false, 'acceso_comisiones' => false, 'recarga_telas' => false,
            'acceso_surtir' => false, 'acceso_costos' => false, 'acceso_proveedores' => false,
            'acceso_despacho' => false, 'acceso_produccion' => false, 'acceso_reserva' => false,
        ];
        $roles = [
            ['clave' => 'vendedor',    'nombre' => 'Vendedor',    'arquetipo' => 'vendedor',   'orden' => 10, 'acceso_proveedores' => true, 'acceso_reserva' => true, 'acceso_surtir' => true],
            ['clave' => 'supervisor',  'nombre' => 'Supervisor',  'arquetipo' => 'supervisor', 'orden' => 20],
            ['clave' => 'conductor',   'nombre' => 'Conductor',   'arquetipo' => 'conductor',  'orden' => 30],
            ['clave' => 'ebanista',    'nombre' => 'Ebanista',    'arquetipo' => 'taller',     'orden' => 40],
            ['clave' => 'despachador', 'nombre' => 'Despachador', 'arquetipo' => 'despachador','orden' => 50],
            ['clave' => 'costurero',   'nombre' => 'Costurero',   'arquetipo' => 'taller',     'orden' => 60],
        ];
        // Cada fila del insert masivo tiene que traer exactamente las mismas
        // columnas, o Laravel arma mal la lista de columnas del INSERT.
        foreach ($roles as &$r) {
            $r = array_merge($flagsDefault, $r);
            $r['activo'] = true;
            $r['created_at'] = $ahora;
            $r['updated_at'] = $ahora;
        }
        unset($r);
        DB::table('roles')->insert($roles);

        // ENUM -> VARCHAR: una clave nueva que invente una empresa (ej.
        // 'bodeguero_jefe') no cabría en el enum fijo de hoy.
        DB::statement("ALTER TABLE usuarios MODIFY COLUMN rol VARCHAR(60) NOT NULL DEFAULT 'vendedor'");

        Schema::table('usuarios', function (Blueprint $table) {
            $table->foreignId('rol_id')->nullable()->after('rol')->constrained('roles')->nullOnDelete();
        });

        foreach (DB::table('roles')->get(['id', 'clave']) as $rol) {
            DB::table('usuarios')->where('rol', $rol->clave)->update(['rol_id' => $rol->id]);
        }
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rol_id');
        });
        DB::statement("ALTER TABLE usuarios MODIFY COLUMN rol ENUM('vendedor','supervisor','conductor','ebanista','despachador','costurero') NOT NULL DEFAULT 'vendedor'");
        Schema::dropIfExists('roles');
    }
};
