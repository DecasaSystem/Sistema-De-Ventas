<?php

namespace App\Services\Finanzas;

use App\Models\Gasto;
use App\Models\GastoRecurrente;
use App\Services\CicloNomina;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Los periodos de cada gasto recurrente, calculados del calendario como los
 * ciclos de nómina: el internet del día 5 "debe" octubre por el solo hecho de
 * que es octubre. Nada se guarda hasta que se paga (u omite): así no hay
 * tarea que crear meses, no se duplican filas y no queda basura.
 *
 * Un periodo se identifica por la fecha en que empieza ('2026-10-01'); es la
 * clave única con la plantilla en `gastos.periodo`.
 */
class ObligacionesRecurrentes
{
    /** Cuántos meses tiene cada periodo de las frecuencias por meses. */
    private const MESES = ['mensual' => 1, 'bimestral' => 2, 'trimestral' => 3, 'semestral' => 6, 'anual' => 12];

    /** Hasta dónde se mira hacia atrás lo no pagado (como la nómina). */
    private const DIAS_ATRAS = 120;

    /**
     * Los periodos de una plantilla cuyo vencimiento cae en el rango.
     *
     * @return array<int, array{periodo: string, vence: string, cubre_desde: string, cubre_hasta: string}>
     */
    public static function periodos(GastoRecurrente $g, Carbon $desde, Carbon $hasta): array
    {
        $inicioPlantilla = Carbon::parse($g->desde->toDateString(), Periodo::TZ)->startOfDay();
        $finPlantilla = $g->hasta ? Carbon::parse($g->hasta->toDateString(), Periodo::TZ)->startOfDay() : null;
        $out = [];

        if (isset(self::MESES[$g->frecuencia])) {
            $paso = self::MESES[$g->frecuencia];
            $c = $inicioPlantilla->copy()->startOfMonth();
            for ($i = 0; $i < 400; $i++, $c->addMonthsNoOverflow($paso)) {
                $ini   = $c->copy();
                $fin   = $c->copy()->addMonthsNoOverflow($paso)->subDay();
                $vence = self::diaDePago($ini, $g->dia_pago ?: $inicioPlantilla->day);
                if ($vence->greaterThan($hasta)) break;
                if ($finPlantilla && $ini->greaterThan($finPlantilla)) break;
                if ($vence->lessThan($desde) || $vence->lessThan($inicioPlantilla)) continue;
                $out[] = self::periodo($g, $ini, $fin, $vence);
            }
        } else {
            // Semanal (lunes a domingo) y quincenal (1–15, 16–fin): los mismos
            // ciclos de la nómina; se paga el último día.
            [$ini, $fin] = CicloNomina::rango($g->frecuencia, $inicioPlantilla);
            for ($i = 0; $i < 400; $i++) {
                if ($fin->greaterThan($hasta)) break;
                if ($finPlantilla && $ini->greaterThan($finPlantilla)) break;
                if ($fin->greaterThanOrEqualTo($desde)) {
                    $out[] = self::periodo($g, $ini, $fin, $fin->copy());
                }
                [$ini, $fin] = CicloNomina::siguiente($g->frecuencia, $ini);
            }
        }

        return $out;
    }

    /**
     * Lo que está por pagar: periodos vencidos (hasta 120 días atrás) y los
     * que vencen en los próximos `$dias`, sin gasto pagado ni omitido.
     */
    public static function pendientes(int $dias = 45, ?Carbon $hoy = null): Collection
    {
        $hoy ??= Periodo::hoy();
        $desde = $hoy->copy()->subDays(self::DIAS_ATRAS);
        $hasta = $hoy->copy()->addDays($dias);

        $plantillas = self::plantillasActivas();
        if ($plantillas->isEmpty()) return collect();

        // El resumen pide pendientes dos veces (semáforo y calendario) y la
        // proyección otra: lo que no depende de `$dias`, una vez por petición.
        $hechos = Periodo::recordar('recurrentes-hechos', fn () => Gasto::whereIn('gasto_recurrente_id', $plantillas->pluck('id'))
            ->whereIn('estado', [Gasto::PAGADO, Gasto::OMITIDO])
            ->get(['gasto_recurrente_id', 'periodo'])
            ->map(fn ($g) => $g->gasto_recurrente_id . '|' . $g->periodo)
            ->flip());

        $sugeridos = self::montosSugeridosActivas();

        $out = collect();
        foreach ($plantillas as $g) {
            foreach (self::periodos($g, $desde, $hasta) as $p) {
                if ($hechos->has($g->id . '|' . $p['periodo'])) continue;

                $diasParaVencer = (int) $hoy->diffInDays(Carbon::parse($p['vence'], Periodo::TZ), false);

                $out->push($p + [
                    'gasto_recurrente_id' => $g->id,
                    'nombre'            => $g->nombre,
                    'categoria_id'      => $g->categoria_gasto_id,
                    'categoria'         => $g->categoria?->nombre,
                    'icono'             => $g->categoria?->icono,
                    'naturaleza'        => $g->categoria?->naturaleza,
                    'tienda_id'         => $g->tienda_id,
                    'tienda'            => $g->tienda?->nombre,
                    'monto'             => $sugeridos[$g->id],
                    'monto_estimado'    => (bool) $g->monto_estimado,
                    'metodo_pago'       => $g->metodo_pago,
                    'dias_para_vencer'  => $diasParaVencer,
                    'estado'            => $diasParaVencer < 0 ? 'vencida'
                        : ($diasParaVencer <= $g->avisar_dias_antes ? 'por_vencer' : 'programada'),
                    'avisar_dias_antes' => $g->avisar_dias_antes,
                ]);
            }
        }

        return $out->sortBy('vence')->values();
    }

    /** Las plantillas activas, una vez por petición. */
    public static function plantillasActivas(): Collection
    {
        return Periodo::recordar('recurrentes-activas', fn () => GastoRecurrente::with(['categoria:id,nombre,icono,naturaleza', 'tienda:id,nombre'])
            ->where('activo', true)->get());
    }

    /** montosSugeridos de las plantillas activas, una vez por petición. */
    public static function montosSugeridosActivas(): array
    {
        return Periodo::recordar('recurrentes-sugeridos', fn () => self::montosSugeridos(self::plantillasActivas()));
    }

    /**
     * El monto que se sugiere: el fijo, o —si cambia (luz, agua)— el promedio
     * de los últimos tres pagos, con el de la plantilla mientras no haya historia.
     *
     * @return array<int, float>
     */
    public static function montosSugeridos(Collection $plantillas): array
    {
        $estimadas = $plantillas->where('monto_estimado', true)->pluck('id');
        $historia = $estimadas->isEmpty() ? collect() : Gasto::whereIn('gasto_recurrente_id', $estimadas)
            ->where('estado', Gasto::PAGADO)->orderByDesc('fecha_pago')
            ->get(['gasto_recurrente_id', 'monto'])->groupBy('gasto_recurrente_id');

        $out = [];
        foreach ($plantillas as $g) {
            $ultimos = ($historia[$g->id] ?? collect())->take(3)->map(fn ($x) => (float) $x->monto);
            $out[$g->id] = $g->monto_estimado && $ultimos->isNotEmpty()
                ? round($ultimos->avg())
                : (float) $g->monto;
        }

        return $out;
    }

    /** El periodo exacto (para pagar uno): null si esa plantilla no tiene ese periodo. */
    public static function buscar(GastoRecurrente $g, string $periodo): ?array
    {
        $dia = Carbon::parse($periodo, Periodo::TZ)->startOfDay();
        foreach (self::periodos($g, $dia->copy()->subYear(), $dia->copy()->addYears(2)) as $p) {
            if ($p['periodo'] === $periodo) return $p;
        }

        return null;
    }

    private static function periodo(GastoRecurrente $g, Carbon $ini, Carbon $fin, Carbon $vence): array
    {
        // Lo que cubre en el estado de resultados: el periodo, si es de un mes
        // o menos, o si se pidió prorratear (la licencia anual en sus 12 meses).
        // Si no, todo cae en el mes en que se paga.
        $repartir = in_array($g->frecuencia, ['semanal', 'quincenal', 'mensual'], true) || $g->prorratear;

        return [
            'periodo'     => $ini->toDateString(),
            'vence'       => $vence->toDateString(),
            'cubre_desde' => ($repartir ? $ini : $vence)->toDateString(),
            'cubre_hasta' => ($repartir ? $fin : $vence)->toDateString(),
        ];
    }

    /** El día de pago en el mes; 31 (o más días que los del mes) = el último. */
    private static function diaDePago(Carbon $mes, int $dia): Carbon
    {
        return $mes->copy()->day(min(max(1, $dia), $mes->daysInMonth));
    }
}
