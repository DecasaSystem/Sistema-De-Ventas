<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Precio de hoy de lo que una ficha técnica tiene enlazado: un material del catálogo
 * (su precio unitario) o un trabajo de Tarifas (el incentivo por hora de su oficio).
 *
 * Es la misma regla con la que Materiales y Tarifas recalculan las fichas cuando cambia
 * un precio (`MaterialController::update`, `ConfiguracionCostosController::guardar`):
 * guardar una ficha no puede dar otra cifra que la que daría ese recálculo.
 *
 * Recuerda lo que consulta mientras vive (una ficha trae muchos ítems del mismo material).
 */
class PreciosVigentes
{
    private array $materiales = [];
    private array $tarifas    = [];

    /** null = no está enlazado (o el oficio no tiene incentivo): manda el precio escrito. */
    public function de(?int $materialId, ?int $tarifaProcesoId): ?float
    {
        if ($materialId) return $this->material($materialId);
        if ($tarifaProcesoId) return $this->tarifa($tarifaProcesoId);

        return null;
    }

    private function material(int $id): ?float
    {
        if (! array_key_exists($id, $this->materiales)) {
            $precio = DB::table('materiales')->where('id', $id)->value('precio_unitario');
            $this->materiales[$id] = $precio === null ? null : (float) $precio;
        }

        return $this->materiales[$id];
    }

    /** Igual que al guardar Tarifas: solo si el oficio tiene incentivo por hora. */
    private function tarifa(int $id): ?float
    {
        if (! array_key_exists($id, $this->tarifas)) {
            $hora = DB::table('tarifas_proceso as tp')
                ->join('salarios_cargo as sc', 'sc.cargo', '=', 'tp.cargo')
                ->where('tp.id', $id)
                ->value('sc.tarifa_hora');
            $this->tarifas[$id] = $hora !== null && (float) $hora > 0 ? round((float) $hora, 2) : null;
        }

        return $this->tarifas[$id];
    }
}
