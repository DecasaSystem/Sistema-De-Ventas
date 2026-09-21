<?php

use App\Http\Controllers\ComisionController;
use App\Models\TiendaReemplazo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Un reemplazo que se quedó sin anotar: Manuela cubrió a Sebastián en Vía El
 * Edén del 18 al 22 de agosto de 2026. Sebastián trabajó del 1 al 17 y volvió
 * el 23.
 *
 * Sin el registro, agosto se repartía como si Sebastián hubiera estado el
 * mes entero: a él le salía de más y a Manuela de menos. Se registra por la
 * misma puerta que el botón de Reemplazos (ComisionController::
 * registrarMovimiento), con sus mismas comprobaciones, y el reparto de
 * agosto se rehace solo.
 *
 * La gente y la tienda se buscan por nombre. Si algo no cuadra —no están,
 * hay dos con ese nombre, ya existe el reemplazo, o Sebastián no figura en
 * el equipo de agosto— se deja anotado en el log y no se toca nada: esto se
 * puede registrar a mano desde Comisiones → Reemplazos.
 */
return new class extends Migration
{
    private const DESDE = '2026-08-18';
    private const HASTA = '2026-08-22';

    public function up(): void
    {
        $tienda    = $this->unico('tiendas',  'nombre', '%Ed_n%', 'la tienda Vía El Edén');
        $manuela   = $this->unico('usuarios', 'nombre', 'Manuela%', 'Manuela');
        $sebastian = $this->unico('usuarios', 'nombre', 'Sebasti_n%', 'Sebastián');
        if (! $tienda || ! $manuela || ! $sebastian) return;

        $yaEsta = TiendaReemplazo::where('tienda_id', $tienda)
            ->where('usuario_id', $manuela)
            ->where('desde', '<=', self::HASTA)
            ->where(fn ($q) => $q->whereNull('hasta')->orWhere('hasta', '>=', self::DESDE))
            ->exists();
        if ($yaEsta) {
            Log::info('Reemplazo de Manuela en El Edén (ago 18-22): ya estaba registrado, no se toca.');
            return;
        }

        $resultado = ComisionController::registrarMovimiento([
            'tienda_id'      => $tienda,
            'tipo'           => TiendaReemplazo::REEMPLAZO,
            'usuario_id'     => $manuela,
            'reemplaza_a_id' => $sebastian,
            'desde'          => self::DESDE,
            'hasta'          => self::HASTA,
            'nota'           => 'Cubrió a Sebastián (registrado tarde, por migración)',
        ]);

        if (is_string($resultado)) {
            Log::warning("Reemplazo de Manuela en El Edén (ago 18-22) no se pudo registrar: {$resultado}");
            return;
        }

        Log::info("Reemplazo de Manuela en El Edén (ago 18-22) registrado: #{$resultado->id}. Reparto de agosto rehecho.");
    }

    /** El id de la única fila que se llame así, o null (y una nota) si no es una sola. */
    private function unico(string $tabla, string $columna, string $patron, string $que): ?int
    {
        $ids = DB::table($tabla)->where($columna, 'like', $patron)
            ->when($tabla === 'usuarios', fn ($q) => $q->where('activo', true))
            ->pluck('id');

        if ($ids->count() !== 1) {
            Log::warning("Reemplazo de Manuela en El Edén: {$que} no se encontró o hay más de una ({$ids->count()}); regístralo a mano en Comisiones → Reemplazos.");
            return null;
        }

        return (int) $ids->first();
    }

    public function down(): void
    {
        // Quitar un reemplazo real no es cosa de un rollback: se hace desde la pantalla.
    }
};
