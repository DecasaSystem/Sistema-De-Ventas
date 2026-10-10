<?php

namespace App\Jobs;

use App\Models\Usuario;
use App\Services\Finanzas\ObligacionesRecurrentes;
use App\Services\NotificacionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Avisa a quien ve Finanzas los gastos fijos que están por vencer (los días
 * que pide cada plantilla), los que vencen hoy y los que vencieron ayer.
 *
 * Un aviso por persona con la lista, no uno por gasto: diez avisos el día 5
 * serían ruido. Y solo esos tres días de cada gasto, para no repetir el mismo
 * todos los días; lo que siga vencido se ve en Finanzas y en el semáforo.
 */
class AvisarGastosPorVencer implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(): void
    {
        $avisar = ObligacionesRecurrentes::pendientes(30)->filter(fn ($o) =>
            $o['dias_para_vencer'] === (int) $o['avisar_dias_antes']
            || $o['dias_para_vencer'] === 0
            || $o['dias_para_vencer'] === -1
        );
        if ($avisar->isEmpty()) return;

        $vencidos = $avisar->where('dias_para_vencer', '<', 0);
        $total = $avisar->sum('monto');
        $lista = $avisar->take(5)->map(fn ($o) => $o['nombre'] . ' ($' . number_format($o['monto'], 0, ',', '.')
            . ($o['dias_para_vencer'] === 0 ? ', hoy' : ($o['dias_para_vencer'] < 0 ? ', vencido' : ", en {$o['dias_para_vencer']} días")) . ')')
            ->implode(' · ');

        $titulo = $vencidos->count()
            ? 'Gastos vencidos sin pagar'
            : 'Gastos por pagar';
        $mensaje = $avisar->count() . ' gasto(s) por $' . number_format($total, 0, ',', '.') . ': ' . $lista
            . ($avisar->count() > 5 ? '…' : '');

        $quienes = Usuario::where('activo', true)->where('rol', 'supervisor')->where('acceso_finanzas', true)->pluck('id');
        foreach ($quienes as $id) {
            NotificacionService::crear('finanzas', $titulo, $mensaje, ['ruta' => 'finanzas', 'pestana' => 'gastos'], $id, $vencidos->isNotEmpty());
        }
    }
}
