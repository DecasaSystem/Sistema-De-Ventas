<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los roles que creó la empresa pasan a comportarse como su arquetipo.
 *
 * Hasta aquí quien tenía un rol nuevo quedaba con `usuarios.rol` = la clave
 * del rol (ej. "gerente"), y el programa pregunta por `supervisor`,
 * `vendedor`, `conductor`... Un "Gerente" de tipo supervisor no aprobaba
 * nada ni entraba a Gestión. Desde ahora `Usuario::booted` guarda la palabra
 * del arquetipo (`Rol::rolEnElCodigo`); esto pone al día a los que ya tenían
 * un rol nuevo asignado.
 *
 * Solo toca a los de roles que NO vinieron de fábrica. Los de fábrica
 * (vendedor, supervisor, ebanista, costurero...) quedan igual. No borra ni
 * renombra nada; la clave del rol sigue en `roles.clave`.
 */
return new class extends Migration
{
    private const CLAVES_DE_FABRICA = ['vendedor', 'supervisor', 'conductor', 'ebanista', 'despachador', 'costurero'];

    // Sin 'taller': esos se quedan con su clave (ver Rol::PALABRA_DEL_ARQUETIPO).
    // Revisado en producción el 2026-11-19: los únicos roles nuevos son de
    // taller, así que hoy esta migración no le cambia nada a nadie allá.
    private const PALABRA_DEL_ARQUETIPO = [
        'vendedor'    => 'vendedor',
        'supervisor'  => 'supervisor',
        'conductor'   => 'conductor',
        'despachador' => 'despachador',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasColumn('usuarios', 'rol_id')) {
            return;
        }

        $nuevos = DB::table('roles')
            ->whereNotIn('clave', self::CLAVES_DE_FABRICA)
            ->get(['id', 'arquetipo']);

        foreach ($nuevos as $rol) {
            $palabra = self::PALABRA_DEL_ARQUETIPO[$rol->arquetipo] ?? null;
            if (! $palabra) {
                continue;
            }
            DB::table('usuarios')->where('rol_id', $rol->id)->update(['rol' => $palabra]);
        }
    }

    /** Vuelve a dejar la clave del rol, como antes. */
    public function down(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasColumn('usuarios', 'rol_id')) {
            return;
        }

        foreach (DB::table('roles')->whereNotIn('clave', self::CLAVES_DE_FABRICA)->get(['id', 'clave']) as $rol) {
            DB::table('usuarios')->where('rol_id', $rol->id)->update(['rol' => $rol->clave]);
        }
    }
};
