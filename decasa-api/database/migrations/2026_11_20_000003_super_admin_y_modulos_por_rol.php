<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Super admin, rangos y módulos de venta por rol
 * (docs/metodologia-de-trabajo-por-rol.md, fase 3). Solo agrega.
 *
 * - `usuarios.es_super_admin`: la marca. Nadie la tiene al migrar: el primero
 *   se pone con `php artisan usuarios:super-admin <correo>`. Mientras no haya
 *   ninguno, todo sigue como hoy.
 * - `roles.modulos_venta`: los módulos de venta que trae el rol (null = los de
 *   su tipo: vendedor y supervisor, ver ModulosDeVenta::DEL_ARQUETIPO).
 * - `usuarios.modulos_ajustados`: lo que a esa persona se le agregó o quitó
 *   encima de su rol. Se llena con lo que cada uno tiene hoy, para que nadie
 *   gane ni pierda nada (los vendedores tienen Caja, que el rol Vendedor ya no
 *   trae: les queda como "agregado").
 * - `permisos_bitacora`: quién le cambió qué permisos a quién.
 */
return new class extends Migration
{
    private const MODULOS = [
        'acceso_nueva_orden', 'acceso_ordenes', 'acceso_clientes', 'acceso_cotizaciones',
        'acceso_caja', 'acceso_consultas_costo', 'acceso_estadisticas',
    ];

    // Lo mismo que ModulosDeVenta::DEL_ARQUETIPO (copiado: una migración no
    // debe depender de código que puede cambiar después).
    private const DEL_ARQUETIPO = [
        'vendedor'   => ['acceso_nueva_orden', 'acceso_ordenes', 'acceso_clientes', 'acceso_cotizaciones',
                         'acceso_consultas_costo', 'acceso_estadisticas'],
        'supervisor' => ['acceso_nueva_orden', 'acceso_ordenes', 'acceso_clientes', 'acceso_cotizaciones',
                         'acceso_caja', 'acceso_consultas_costo', 'acceso_estadisticas'],
    ];

    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $t) {
            if (! Schema::hasColumn('usuarios', 'es_super_admin')) {
                $t->boolean('es_super_admin')->default(false);
            }
            if (! Schema::hasColumn('usuarios', 'modulos_ajustados')) {
                $t->json('modulos_ajustados')->nullable();
            }
        });

        if (Schema::hasTable('roles') && ! Schema::hasColumn('roles', 'modulos_venta')) {
            Schema::table('roles', fn (Blueprint $t) => $t->json('modulos_venta')->nullable());
        }

        if (! Schema::hasTable('permisos_bitacora')) {
            Schema::create('permisos_bitacora', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('actor_id')->nullable();
                $t->unsignedBigInteger('usuario_id');
                $t->json('cambios');
                $t->timestamp('created_at')->nullable();
                $t->index('usuario_id');
            });
        }

        // Lo que cada uno tiene hoy, como ajuste encima de su rol.
        $arquetipos = Schema::hasTable('roles')
            ? DB::table('roles')->pluck('arquetipo', 'id')->all()
            : [];

        DB::table('usuarios')->orderBy('id')->get(array_merge(['id', 'rol', 'rol_id'], self::MODULOS))
            ->each(function ($u) use ($arquetipos) {
                $arquetipo = $arquetipos[$u->rol_id] ?? $u->rol;
                $delRol = self::DEL_ARQUETIPO[$arquetipo] ?? [];
                $ajustes = [];
                foreach (self::MODULOS as $c) {
                    $tiene = (bool) $u->$c;
                    if ($tiene !== in_array($c, $delRol, true)) {
                        $ajustes[$c] = $tiene;
                    }
                }
                DB::table('usuarios')->where('id', $u->id)
                    ->update(['modulos_ajustados' => $ajustes ? json_encode($ajustes) : null]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('permisos_bitacora');
        if (Schema::hasTable('roles') && Schema::hasColumn('roles', 'modulos_venta')) {
            Schema::table('roles', fn (Blueprint $t) => $t->dropColumn('modulos_venta'));
        }
        Schema::table('usuarios', function (Blueprint $t) {
            foreach (['es_super_admin', 'modulos_ajustados'] as $c) {
                if (Schema::hasColumn('usuarios', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
