<?php

namespace App\Console\Commands;

use App\Models\Usuario;
use App\Support\RangoDeUsuarios;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Marca al primer super admin (o a otro, desde la consola).
 *
 * Desde la app nadie se puede hacer el primero: si se pudiera, el primer
 * supervisor que llegara se lo pondría. Por eso va por aquí, a mano y con el
 * correo de la persona (docs/metodologia-de-trabajo-por-rol.md §8).
 *
 *   php artisan usuarios:super-admin correo@ejemplo.com
 */
class MarcarSuperAdmin extends Command
{
    protected $signature = 'usuarios:super-admin {correo : El correo con el que entra la persona}';

    protected $description = 'Marca como super admin a la persona de ese correo';

    public function handle(): int
    {
        $u = Usuario::where('email', $this->argument('correo'))->first();

        if (! $u) {
            $this->error('No hay nadie con ese correo.');
            return self::FAILURE;
        }
        if (! $u->activo) {
            $this->error("{$u->nombre} está desactivado.");
            return self::FAILURE;
        }
        if ($u->es_super_admin) {
            $this->info("{$u->nombre} ya es super admin.");
            return self::SUCCESS;
        }

        $u->forceFill(['es_super_admin' => true])->saveQuietly();
        DB::table('permisos_bitacora')->insert([
            'actor_id'   => null,
            'usuario_id' => $u->id,
            'cambios'    => json_encode(['es_super_admin' => [false, true], 'desde' => 'consola']),
            'created_at' => now(),
        ]);
        RangoDeUsuarios::olvidar();

        $this->info("Listo: {$u->nombre} ({$u->rol}) es super admin.");

        return self::SUCCESS;
    }
}
