<?php

namespace App\Support;

use App\Models\Rol;
use App\Models\Usuario;
use App\Services\NotificacionService;
use Illuminate\Support\Facades\DB;

/**
 * Quién le puede cambiar qué a quién (usuario, 2026-11-19; ver
 * docs/metodologia-de-trabajo-por-rol.md §8). Es una escala:
 *
 * - **Super admin** (marca por persona, encima de su rol): le cambia todo a
 *   todos. Crea y cambia roles. Nombra o quita a otro super admin.
 * - **Supervisor**: le pone y quita módulos a los que no son supervisores
 *   (vendedores, conductores, fábrica), y los crea. A otro supervisor, a un
 *   super admin o a sí mismo, no: "que los supervisores no se quiten cosas
 *   entre ellos". Nunca le pone a nadie un rol de tipo supervisor.
 * - **Nadie cambia sus propios permisos**, salvo el único super admin.
 *
 * Mientras no haya ningún super admin (recién subido, antes de correr
 * `usuarios:super-admin`), todo sigue como antes: cualquier supervisor.
 * La marca de super admin nunca se pone desde la app si no hay uno.
 */
final class RangoDeUsuarios
{
    /**
     * Los campos de la ficha que son permisos. Lo demás (nombre, cédula,
     * nómina, tienda…) lo sigue editando el supervisor como siempre.
     */
    public const PERMISOS = [
        'rol_id', 'es_super_admin', 'facturacion', 'independiente', 've_todas_ordenes', 'puede_fv2_sin_iva',
        'acceso_redes', 'acceso_comisiones', 'acceso_reglas_comision', 'recarga_telas', 'acceso_telas',
        'acceso_surtir', 'acceso_costos', 'acceso_proveedores', 'acceso_despacho', 'acceso_entregas',
        'acceso_produccion', 'gestiona_produccion', 'acceso_reserva', 'acceso_nomina', 'acceso_finanzas',
        'acceso_compras', 'lleva_encargos', 'acceso_encargos', 'revisa_encargos',
        'acceso_nueva_orden', 'acceso_ordenes', 'acceso_clientes', 'acceso_cotizaciones', 'acceso_caja',
        'acceso_consultas_costo', 'acceso_estadisticas', 'asigna_fechas', 'edita_catalogo',
        'acceso_traslado',
    ];

    /** Los delicados: cuando alguien que no es super admin los da, se le avisa al super admin. */
    public const DELICADOS = [
        'facturacion'            => 'Facturación',
        'acceso_costos'          => 'Costos',
        'acceso_comisiones'      => 'Bonificaciones',
        'acceso_reglas_comision' => 'Reglas de bonificación',
        'acceso_nomina'          => 'Nómina',
        'acceso_finanzas'        => 'Finanzas',
        'acceso_compras'         => 'Compras',
        'acceso_despacho'        => 'Despacho',
        'edita_catalogo'         => 'Editar catálogo (precios)',
    ];

    /** Recordado por petición: la lista de Trabajadores lo pregunta por cada fila. */
    private static ?\Illuminate\Support\Collection $superAdmins = null;

    public static function olvidar(): void
    {
        self::$superAdmins = null;
    }

    public static function activo(): bool
    {
        return self::superAdmins()->isNotEmpty();
    }

    public static function esSuperAdmin(?Usuario $u): bool
    {
        return (bool) ($u?->es_super_admin ?? false);
    }

    public static function superAdmins(): \Illuminate\Support\Collection
    {
        if (! Esquema::columna('usuarios', 'es_super_admin')) return collect();

        return self::$superAdmins ??= Usuario::where('es_super_admin', true)->where('activo', true)->get();
    }

    /** ¿Es de rango supervisor (o más)? */
    public static function esDeRangoAlto(Usuario $u): bool
    {
        return $u->rol === 'supervisor' || self::esSuperAdmin($u);
    }

    /**
     * ¿Puede `$quien` administrar a `$a` (cambiarle permisos, desactivarlo,
     * cambiarle la contraseña, sus avisos)? Devuelve el porqué si no.
     */
    public static function porQueNoPuede(Usuario $quien, Usuario $a): ?string
    {
        if (! self::activo()) return null;
        if ($quien->id === $a->id) return null; // a sí mismo: lo mira puedeCambiarseLoSuyo
        if (self::esSuperAdmin($quien)) return null;
        if (self::esSuperAdmin($a)) return 'A un super admin solo lo cambia otro super admin.';
        if ($a->rol === 'supervisor') return 'A un supervisor solo lo cambia el super admin.';

        return null;
    }

    /** ¿Se puede cambiar sus propios permisos? Solo el único super admin. */
    public static function puedeCambiarseLoSuyo(Usuario $quien): bool
    {
        if (! self::activo()) return true; // como antes
        if (! self::esSuperAdmin($quien)) return false;

        return self::superAdmins()->count() === 1;
    }

    /** ¿Puede `$quien` darle a alguien un rol de tipo supervisor? */
    public static function puedeDarRol(Usuario $quien, ?Rol $rol): bool
    {
        if (! $rol || ! self::activo() || self::esSuperAdmin($quien)) return true;

        return $rol->arquetipo !== 'supervisor';
    }

    /** ¿Puede crear, cambiar o borrar roles? */
    public static function puedeTocarRoles(Usuario $quien): bool
    {
        return ! self::activo() || self::esSuperAdmin($quien);
    }

    /**
     * Lo que de verdad cambia en los permisos de `$u` con `$nuevos`
     * (la pantalla manda la ficha entera: hay que comparar).
     * Devuelve campo => [antes, después].
     */
    public static function cambios(Usuario $u, array $nuevos): array
    {
        $out = [];
        foreach (self::PERMISOS as $c) {
            if (! array_key_exists($c, $nuevos)) continue;
            $antes = $c === 'rol_id' ? ($u->rol_id ? (int) $u->rol_id : null) : (bool) $u->getAttribute($c);
            $despues = $c === 'rol_id' ? ($nuevos[$c] !== null ? (int) $nuevos[$c] : null) : (bool) $nuevos[$c];
            if ($antes !== $despues) {
                $out[$c] = [$antes, $despues];
            }
        }

        return $out;
    }

    /**
     * Deja el rastro (quién, a quién, qué) y, si alguien que no es super admin
     * dio algo delicado, le avisa al super admin.
     */
    public static function anotar(?Usuario $quien, Usuario $a, array $cambios): void
    {
        if (! $cambios) return;

        if (Esquema::tabla('permisos_bitacora')) {
            DB::table('permisos_bitacora')->insert([
                'actor_id'   => $quien?->id,
                'usuario_id' => $a->id,
                'cambios'    => json_encode($cambios),
                'created_at' => now(),
            ]);
        }

        if (! $quien || self::esSuperAdmin($quien)) return;

        $dados = collect(self::DELICADOS)
            ->filter(fn ($nombre, $c) => isset($cambios[$c]) && $cambios[$c][1] === true)
            ->values()->all();
        if (! $dados) return;

        foreach (self::superAdmins() as $admin) {
            NotificacionService::crear(
                'permiso_delicado',
                'Se dio un permiso delicado',
                "{$quien->nombre} le dio a {$a->nombre}: " . implode(', ', $dados) . '.',
                ['usuario_id' => $a->id],
                $admin->id,
            );
        }
    }
}
