<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El rol dice QUÉ ES la persona. Los pasos del taller se asignan aparte.
 *
 * Estaban mezcladas dos cosas distintas bajo la misma palabra:
 *
 *  - Henry tenía rol "Ebanista", pero no es ebanista de oficio: es un vendedor
 *    independiente que además está encargado de los pasos de ebanistería.
 *  - Mónica tenía la bandera `es_tapicero`, pero no es tapicera: es supervisora
 *    encargada de los pasos de tapizado.
 *  - Los tapiceros y ebanistas DE VERDAD son los de fábrica, que ni siquiera
 *    entran al programa. Para ellos "Tapicero" sí es su oficio y se queda.
 *
 * Eso hacía que el rol sirviera para dos cosas a la vez y ninguna bien. El
 * daño concreto: `rol = 'ebanista'` se usaba en ocho sitios para decir "es un
 * vendedor independiente", así que los seis ebanistas de fábrica aparecían en
 * Caja y en los reportes como vendedores con caja propia, sin haber vendido
 * nunca nada.
 *
 * A partir de aquí:
 *  - Quién vende por su cuenta  -> la bandera `independiente`, que ya existía.
 *  - Qué pasos del taller lleva -> `proceso_trabajadores`, uno por uno.
 *  - Qué es la persona          -> su rol, y ya.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Los perfiles de producción se vuelven pasos asignados ──────────
        // Antes el perfil "tapicero" abría en bloque todos los pasos que
        // declaraban ese perfil. Se convierte a asignaciones explícitas para
        // que el encargado se vea en pantalla y se pueda cambiar uno por uno.
        if (Schema::hasColumn('usuarios', 'perfil_produccion_id')) {
            $procesos = DB::table('tipos_proceso')->get(['id', 'clave', 'perfiles']);

            $porPerfil = [];   // clave de perfil => [ids de tipos_proceso]
            foreach ($procesos as $p) {
                foreach (json_decode($p->perfiles ?? '[]', true) ?: [] as $perfil) {
                    $porPerfil[$perfil][] = $p->id;
                }
            }

            $usuarios = DB::table('usuarios as u')
                ->leftJoin('perfiles_produccion as pp', 'pp.id', '=', 'u.perfil_produccion_id')
                ->whereNotNull('u.perfil_produccion_id')
                ->orWhere('u.es_tapicero', true)
                ->get(['u.id', 'u.es_tapicero', 'pp.clave']);

            foreach ($usuarios as $u) {
                // `es_tapicero` era otra forma de decir lo mismo que el perfil.
                $claves = array_filter([$u->clave, $u->es_tapicero ? 'tapicero' : null]);

                $ids = [];
                foreach ($claves as $c) {
                    $ids = array_merge($ids, $porPerfil[$c] ?? []);
                }

                foreach (array_unique($ids) as $tipoId) {
                    DB::table('proceso_trabajadores')->insertOrIgnore([
                        'usuario_id'      => $u->id,
                        'tipo_proceso_id' => $tipoId,
                    ]);
                }
            }
        }

        // ── 2. Henry deja de ser "Ebanista" y pasa a ser lo que realmente es ──
        // Vendedor independiente. Sus pasos ya quedaron asignados arriba, así
        // que no pierde nada del taller.
        $rolVendedor = DB::table('roles')->where('arquetipo', 'vendedor')->orderBy('id')->first();
        if ($rolVendedor) {
            DB::table('usuarios')
                ->where('rol', 'ebanista')
                ->where('no_usa_programa', false)
                ->update([
                    'rol_id'        => $rolVendedor->id,
                    'rol'           => $rolVendedor->clave,
                    'independiente' => true,
                    // Atendía consultas de costo por ser "ebanista"; ahora eso
                    // es un permiso, para que no dependa de cómo se llame su rol.
                    'acceso_costos' => true,
                ]);
        }

        // ── 3. Las banderas que ya no deciden nada ───────────────────────────
        Schema::table('usuarios', function (Blueprint $table) {
            if (Schema::hasColumn('usuarios', 'perfil_produccion_id')) {
                $table->dropForeign(['perfil_produccion_id']);
                $table->dropColumn('perfil_produccion_id');
            }
            if (Schema::hasColumn('usuarios', 'es_tapicero')) {
                $table->dropColumn('es_tapicero');
            }
        });

        Schema::dropIfExists('perfiles_produccion');

        // Los procesos ya no se reparten por perfil: la columna quedaría
        // diciendo una regla que nadie aplica.
        if (Schema::hasColumn('tipos_proceso', 'perfiles')) {
            Schema::table('tipos_proceso', fn (Blueprint $t) => $t->dropColumn('perfiles'));
        }

        // ── 4. El rol "Despachador" no lo tiene nadie ────────────────────────
        // Despachar es un permiso (`acceso_despacho`), no un cargo: quien
        // despacha es un supervisor o un vendedor con esa casilla marcada.
        DB::table('roles')->where('arquetipo', 'despachador')->update(['activo' => false]);
    }

    public function down(): void
    {
        // No se reconstruyen los perfiles: la información de qué pasos lleva
        // cada quien ya vive, más detallada, en `proceso_trabajadores`.
        Schema::table('usuarios', function (Blueprint $table) {
            $table->boolean('es_tapicero')->default(false);
            $table->foreignId('perfil_produccion_id')->nullable();
        });
        Schema::table('tipos_proceso', fn (Blueprint $t) => $t->json('perfiles')->nullable());
        DB::table('roles')->where('arquetipo', 'despachador')->update(['activo' => true]);
    }
};
