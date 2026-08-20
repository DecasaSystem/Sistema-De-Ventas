<?php

namespace App\Services;

use App\Models\Empleado;
use App\Models\NominaAusencia;
use App\Models\NominaItem;
use App\Models\NominaPeriodo;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Crear un período y sembrarlo con todos los empleados activos — lo usan
 * tanto el endpoint manual (NominaPeriodoController::store) como el job
 * automático (GenerarPeriodoNomina), para no duplicar la lógica.
 */
class NominaPeriodoService
{
    /**
     * Un período pertenece a una sola frecuencia de pago: solo se siembra
     * con los trabajadores activos que tengan esa misma `periodicidad` (no
     * todos los activos) — así conviven por separado un período "Quincenal"
     * y uno "Semanal" sin mezclarse.
     */
    public static function crear(string $nombre, Carbon $inicio, Carbon $fin, string $periodicidad = 'quincenal'): NominaPeriodo
    {
        $dias = $inicio->diffInDays($fin) + 1;

        return DB::transaction(function () use ($nombre, $inicio, $fin, $dias, $periodicidad) {
            $periodo = NominaPeriodo::create([
                'nombre'       => $nombre,
                'periodicidad' => $periodicidad,
                'fecha_inicio' => $inicio,
                'fecha_fin'    => $fin,
                'dias_periodo' => $dias,
            ]);

            $activos = Empleado::with('sueldo')->where('activo', true)->where('periodicidad', $periodicidad)->get();
            foreach ($activos as $empleado) {
                self::sembrarItem($periodo, $empleado, $dias);
            }

            return $periodo;
        });
    }

    /**
     * Un item nuevo para un empleado dentro de un período — usado al crear
     * el período completo y también al agregar un empleado suelto después.
     * Engancha de una vez cualquier ausencia que ya estuviera pendiente
     * (registrada con fecha antes de que este período existiera) y caiga
     * dentro de su rango.
     */
    public static function sembrarItem(NominaPeriodo $periodo, Empleado $empleado, ?int $dias = null): NominaItem
    {
        $item = NominaItem::create([
            'nomina_periodo_id' => $periodo->id,
            'empleado_id'       => $empleado->id,
            'valor_label'       => $empleado->labelEfectivo(),
            'valor_dia'         => $empleado->valorDiaEfectivo(),
            'horas_dia'         => $empleado->horasDiaEfectivo(),
            'dias_trabajados'   => $dias ?? $periodo->dias_periodo,
        ]);

        NominaAusencia::where('empleado_id', $empleado->id)
            ->whereNull('nomina_item_id')
            ->whereBetween('fecha', [$periodo->fecha_inicio->toDateString(), $periodo->fecha_fin->toDateString()])
            ->update(['nomina_item_id' => $item->id]);

        return $item;
    }
}
