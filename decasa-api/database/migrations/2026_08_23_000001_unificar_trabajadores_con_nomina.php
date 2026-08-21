<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un solo lugar donde existe una persona: Trabajadores.
 *
 * Hasta ahora había DOS tablas de gente que no se hablaban: `usuarios` (los
 * que entran al programa) y `empleados` (los de Nómina). La misma persona
 * podía estar en las dos sin ninguna relación — de hecho "Henry" estaba
 * duplicado — y nadie podía responder "cuánta gente trabaja acá" con una
 * sola lista.
 *
 * Ahora el trabajador se crea una sola vez en Trabajadores y aparece solo en
 * Nómina. Los de fábrica se marcan `no_usa_programa`: no llevan correo ni
 * contraseña (no pueden iniciar sesión ni por accidente), no llevan tienda ni
 * permisos, y su `rol` funciona como su oficio (Lijador, Laquero...).
 *
 * Se hace AHORA porque las tablas operativas de Nómina están casi vacías
 * (0 pagos, 0 ajustes, 0 producciones, 1 falta): no hay historial de plata
 * que arrastrar. Con quincenas ya pagadas encima esto sería mucho más caro.
 */
return new class extends Migration
{
    /**
     * cargo del empleado → rol. Lijador/Lijadora y Costurero/Costurera se
     * unifican: un rol es un oficio, no un género, y separarlos obligaría a
     * marcar los dos cada vez que se asigna un paso de producción.
     */
    private const CARGO_A_ROL = [
        'Ebanista'    => 'Ebanista',
        'Tapicero'    => 'Tapicero',
        'Lijador'     => 'Lijador',
        'Lijadora'    => 'Lijador',
        'Costurero'   => 'Costurero',
        'Costurera'   => 'Costurero',
        'Pintor'      => 'Pintor',
        'Pelador'     => 'Pelador',
        'Esqueletero' => 'Esqueletero',
        'Laquero'     => 'Laquero',
    ];

    /** Para los 2 que hoy están sin cargo: hay que ponerles algo, y esto es corregible. */
    private const ROL_SIN_CARGO = 'Operario';

    public function up(): void
    {
        // ── 1. usuarios pasa a poder guardar gente que no entra al programa ──
        Schema::table('usuarios', function (Blueprint $table) {
            $table->string('cedula', 20)->nullable()->unique()->after('nombre');
            // Marca al trabajador de fábrica: sin login, sin tienda, sin permisos.
            $table->boolean('no_usa_programa')->default(false)->after('activo');
            // Quién puede ganar comisión. Se marca a mano al crearlo; quien no
            // usa el programa nunca puede serlo (no hace ventas).
            $table->boolean('apto_comisiones')->default(false)->after('no_usa_programa');
            // Lo que antes vivía en `empleados` y define cómo se le paga.
            $table->foreignId('nomina_sueldo_id')->nullable()->after('apto_comisiones')
                ->constrained('nomina_sueldos')->nullOnDelete();
            $table->foreignId('nomina_bonificacion_id')->nullable()->after('nomina_sueldo_id')
                ->constrained('nomina_bonificaciones')->nullOnDelete();
            $table->enum('periodicidad', ['diario', 'semanal', 'quincenal', '20_dias', 'mensual'])
                ->default('quincenal')->after('nomina_bonificacion_id');
        });

        // Correo y contraseña dejan de ser obligatorios: el de fábrica no tiene.
        // El índice único de email se conserva — MySQL admite varios NULL.
        DB::statement('ALTER TABLE usuarios MODIFY email VARCHAR(255) NULL');
        DB::statement('ALTER TABLE usuarios MODIFY password VARCHAR(255) NULL');

        // ── 2. Quién cobra comisión hoy, para no quitársela a nadie ──────────
        // No basta con vendedor/supervisor: hay un ebanista con comisiones
        // (los independientes), así que se respalda también por los hechos.
        DB::table('usuarios')->whereIn('rol', ['vendedor', 'supervisor'])->update(['apto_comisiones' => true]);
        DB::statement('
            UPDATE usuarios SET apto_comisiones = 1
            WHERE id IN (SELECT DISTINCT vendedor_id FROM comisiones WHERE vendedor_id IS NOT NULL)
        ');

        // ── 3. Los oficios de fábrica pasan a ser roles de verdad ────────────
        $ahora = now();
        $orden = (int) (DB::table('roles')->max('orden') ?? 0);
        $rolIdPorNombre = [];

        // array_merge y no '+': con '+' los arrays se unen por clave y
        // ROL_SIN_CARGO (clave 0) se perdería contra el primer cargo.
        $rolesACrear = array_unique(array_merge(array_values(self::CARGO_A_ROL), [self::ROL_SIN_CARGO]));

        foreach ($rolesACrear as $nombreRol) {
            $clave = \Illuminate\Support\Str::slug($nombreRol, '_');
            $existente = DB::table('roles')->where('clave', $clave)->first();

            if ($existente) {
                $rolIdPorNombre[$nombreRol] = $existente->id;
                continue;
            }

            $orden += 10;
            $rolIdPorNombre[$nombreRol] = DB::table('roles')->insertGetId([
                'clave' => $clave, 'nombre' => $nombreRol,
                // Oficio de taller. Ninguna bandera de acceso encendida: no
                // entran al programa, y si algún día se les da cuenta, se les
                // prende lo que haga falta a mano.
                'arquetipo' => 'taller', 'activo' => true, 'orden' => $orden,
                'acceso_redes' => false, 'acceso_comisiones' => false, 'recarga_telas' => false,
                'acceso_surtir' => false, 'acceso_costos' => false, 'acceso_proveedores' => false,
                'acceso_despacho' => false, 'acceso_produccion' => false, 'acceso_reserva' => false,
                'acceso_nomina' => false, 'acceso_compras' => false,
                'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        }

        // ── 4. Los empleados de Nómina pasan a ser trabajadores ──────────────
        // "Henry" está en las dos tablas; se migra como ficha aparte a
        // propósito (decisión del usuario): separar después es fácil, deshacer
        // una fusión equivocada no.
        $mapa = [];   // empleado_id => usuario_id
        foreach (DB::table('empleados')->orderBy('id')->get() as $e) {
            $nombreRol = self::CARGO_A_ROL[$e->cargo] ?? self::ROL_SIN_CARGO;
            $rolId     = $rolIdPorNombre[$nombreRol];
            $rolClave  = DB::table('roles')->where('id', $rolId)->value('clave');

            $mapa[$e->id] = DB::table('usuarios')->insertGetId([
                'nombre'  => $e->nombre,
                'cedula'  => $e->cedula,
                'email'   => null,
                'password' => null,
                'rol'     => $rolClave,
                'rol_id'  => $rolId,
                'activo'  => $e->activo,
                'no_usa_programa' => true,
                'apto_comisiones' => false,
                'nomina_sueldo_id'       => $e->nomina_sueldo_id,
                'nomina_bonificacion_id' => $e->nomina_bonificacion_id,
                'periodicidad'           => $e->periodicidad,
                // Sin tienda ni permisos: todo lo demás queda en su default.
                'facturacion' => false, 'es_tapicero' => false, 'independiente' => false,
                'notif_asignar_fecha' => false, 'notif_stock' => false,
                'acceso_redes' => false, 'acceso_comisiones' => false, 'recarga_telas' => false,
                'acceso_surtir' => false, 'acceso_costos' => false, 'acceso_proveedores' => false,
                'acceso_despacho' => false, 'acceso_produccion' => false, 'acceso_reserva' => false,
                'acceso_nomina' => false, 'acceso_compras' => false, 've_todas_ordenes' => false,
                // created_at conserva el original: la liquidación cuenta los
                // días desde que la persona está dada de alta.
                'created_at' => $e->created_at ?? $ahora,
            ]);
        }

        // ── 5. Nómina pasa a apuntar al trabajador, no al empleado ───────────
        foreach (['nomina_ausencias', 'nomina_ajustes', 'nomina_pagos', 'nomina_producciones'] as $tabla) {
            Schema::table($tabla, function (Blueprint $t) {
                $t->foreignId('usuario_id')->nullable()->after('id')->constrained('usuarios');
            });

            foreach ($mapa as $empleadoId => $usuarioId) {
                DB::table($tabla)->where('empleado_id', $empleadoId)->update(['usuario_id' => $usuarioId]);
            }

            // Si quedara alguna fila sin mapear se borra el FK igual, pero eso
            // solo pasaría con datos corruptos previos.
            Schema::table($tabla, function (Blueprint $t) {
                $t->dropConstrainedForeignId('empleado_id');
            });
        }

        Schema::dropIfExists('empleados');
    }

    public function down(): void
    {
        // Se recrea `empleados` y se devuelven las filas migradas, para poder
        // volver atrás si algo sale mal en el despliegue.
        Schema::create('empleados', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 120);
            $table->string('cedula', 20)->nullable()->unique();
            $table->string('cargo', 80)->nullable();
            $table->foreignId('nomina_sueldo_id')->nullable()->constrained('nomina_sueldos')->nullOnDelete();
            $table->foreignId('nomina_bonificacion_id')->nullable()->constrained('nomina_bonificaciones')->nullOnDelete();
            $table->enum('periodicidad', ['diario', 'semanal', 'quincenal', '20_dias', 'mensual'])->default('quincenal');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        $mapa = [];
        foreach (DB::table('usuarios')->where('no_usa_programa', true)->get() as $u) {
            $mapa[$u->id] = DB::table('empleados')->insertGetId([
                'nombre' => $u->nombre, 'cedula' => $u->cedula,
                'cargo'  => DB::table('roles')->where('id', $u->rol_id)->value('nombre'),
                'nomina_sueldo_id' => $u->nomina_sueldo_id,
                'nomina_bonificacion_id' => $u->nomina_bonificacion_id,
                'periodicidad' => $u->periodicidad, 'activo' => $u->activo,
                'created_at' => $u->created_at, 'updated_at' => now(),
            ]);
        }

        foreach (['nomina_ausencias', 'nomina_ajustes', 'nomina_pagos', 'nomina_producciones'] as $tabla) {
            Schema::table($tabla, function (Blueprint $t) {
                $t->foreignId('empleado_id')->nullable()->after('id')->constrained('empleados');
            });
            foreach ($mapa as $usuarioId => $empleadoId) {
                DB::table($tabla)->where('usuario_id', $usuarioId)->update(['empleado_id' => $empleadoId]);
            }
            Schema::table($tabla, function (Blueprint $t) {
                $t->dropConstrainedForeignId('usuario_id');
            });
        }

        DB::table('usuarios')->where('no_usa_programa', true)->delete();

        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropConstrainedForeignId('nomina_sueldo_id');
            $table->dropConstrainedForeignId('nomina_bonificacion_id');
            $table->dropColumn(['cedula', 'no_usa_programa', 'apto_comisiones', 'periodicidad']);
        });

        DB::statement('ALTER TABLE usuarios MODIFY email VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE usuarios MODIFY password VARCHAR(255) NOT NULL');
    }
};
