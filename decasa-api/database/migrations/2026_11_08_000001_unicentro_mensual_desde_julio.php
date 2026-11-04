<?php

use App\Http\Controllers\ComisionController;
use App\Models\Comision;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unicentro Pereira deja de liquidar por trimestre: desde julio de 2026 cada
 * mes va por su cuenta. Decisión del dueño, 2026-11-04: "la plata de
 * septiembre es de septiembre", que nada se pague dos veces. Metas: julio y
 * agosto siguen en $40 M, septiembre $50 M y de octubre en adelante $60 M.
 *
 * Es lo mismo que haría un supervisor en Comisiones → Metas ("Se liquida:
 * cada mes" y las dos metas), con el mismo cálculo de fecha de pago que
 * ComisionController::setPeriodicidad y su registro en la bitácora. Unicentro
 * no tiene comisiones antes de julio, así que pasarla a mensual es pasarla
 * desde julio. Simulado en una copia de producción: solo cambia Unicentro
 * (Juan Esteban $1.113.687 → $719.705, Génesis $483.938 → $689.099).
 *
 * No hace nada si encuentra algo pagado de Unicentro desde julio (cambiaría
 * lo ya liquidado) o si la tienda ya es mensual: correrla dos veces no hace nada.
 */
return new class extends Migration
{
    private const TIENDA = 4;
    private const NOMBRE = 'Decasa Unicentro Pereira';

    public function up(): void
    {
        if (! Schema::hasColumn('tiendas', 'comision_periodicidad')) return;

        $tienda = DB::table('tiendas')->where('id', self::TIENDA)->where('nombre', self::NOMBRE)->first();
        if (! $tienda) {
            echo "[Unicentro mensual] No se encontró la tienda: no se cambió nada.\n";
            return;
        }
        if ($tienda->comision_periodicidad !== ComisionController::TRIMESTRAL) {
            echo "[Unicentro mensual] Ya es mensual: no se cambió nada.\n";
            return;
        }
        $pagadas = Comision::where('tienda_id', self::TIENDA)->where('mes_venta', '>=', '2026-07')
            ->where('estado', 'pagada')->exists();
        if ($pagadas) {
            echo "[Unicentro mensual] Hay comisiones pagadas desde julio: no se cambió nada (hay que decidirlo a mano).\n";
            return;
        }

        DB::transaction(function () {
            DB::table('tiendas')->where('id', self::TIENDA)->update(['comision_periodicidad' => ComisionController::MENSUAL]);
            ComisionController::olvidarPeriodicidades();

            $metaAntes = [];
            foreach (['2026-09' => 50_000_000, '2026-10' => 60_000_000] as $mes => $meta) {
                $fila = DB::table('metas_tienda')->where('tienda_id', self::TIENDA)->where('mes', $mes)->first();
                $metaAntes[$mes] = $fila ? (float) $fila->meta : null;
                if ($fila) {
                    DB::table('metas_tienda')->where('id', $fila->id)->update(['meta' => $meta, 'updated_at' => now()]);
                } else {
                    // El divisor viejo se arrastra como en la pantalla de Metas.
                    $divisor = DB::table('metas_tienda')->where('tienda_id', self::TIENDA)->where('mes', '<', $mes)
                        ->orderByDesc('mes')->value('divisor_asesores') ?? 1;
                    DB::table('metas_tienda')->insert(['tienda_id' => self::TIENDA, 'mes' => $mes, 'meta' => $meta,
                        'divisor_asesores' => $divisor, 'created_at' => now(), 'updated_at' => now()]);
                }
            }

            // La fecha de pago cambia: cada mes se paga el 20 del mes siguiente.
            $fecha = new ReflectionMethod(ComisionController::class, 'calcularFechaDisponible');
            foreach (Comision::where('tienda_id', self::TIENDA)->where('estado', '!=', 'pagada')->get() as $c) {
                $c->update(['fecha_disponible' => $fecha->invoke(null, Carbon::parse($c->fecha_venta), self::TIENDA)]);
            }

            ComisionController::anotar('periodicidad', self::TIENDA, null, [
                'antes' => ComisionController::TRIMESTRAL, 'despues' => ComisionController::MENSUAL,
                'nota'  => 'Mensual desde julio 2026 (decisión del dueño, 2026-11-04)',
            ]);
            foreach ($metaAntes as $mes => $antes) {
                ComisionController::anotar('meta', self::TIENDA, $mes, [
                    'antes' => $antes, 'despues' => $mes === '2026-09' ? 50_000_000 : 60_000_000,
                ]);
            }
        });

        ComisionController::pedirRecalculo('migración Unicentro mensual desde julio');
        echo "[Unicentro mensual] Ahora es mensual; metas sep \$50 M y oct \$60 M.\n";
    }

    public function down(): void
    {
        // Es una decisión del negocio: no se deshace.
    }
};
