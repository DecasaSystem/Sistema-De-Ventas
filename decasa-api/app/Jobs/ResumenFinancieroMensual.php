<?php

namespace App\Jobs;

use App\Models\Usuario;
use App\Services\Finanzas\CalendarioPagos;
use App\Services\Finanzas\EstadoResultados;
use App\Services\Finanzas\Indicadores;
use App\Services\Finanzas\Periodo;
use App\Services\NotificacionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * El día 1, al celular de quien ve Finanzas: cómo cerró el mes anterior (ventas,
 * utilidad, margen), lo más importante del semáforo y lo que hay que pagar en
 * la quincena. Un solo aviso, con lo justo para leerlo en la pantalla de
 * bloqueo; el detalle está en Finanzas (el aviso abre ahí).
 */
class ResumenFinancieroMensual implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(): void
    {
        $mes = Periodo::sumarMeses(Periodo::mesActual(), -1);
        [$antes, $m] = EstadoResultados::meses(Periodo::sumarMeses($mes, -1), $mes)['meses'];
        if (! $m['ventas'] && ! $m['nomina']['total']) return;

        $ind = Indicadores::delMes($mes, $m, $antes);
        $pesos = fn ($n) => '$' . number_format((float) $n, 0, ',', '.');
        $margen = $m['margenes']['operativo'] !== null ? number_format($m['margenes']['operativo'] * 100, 1, ',', '.') . ' %' : '—';

        $partes = [
            ($m['utilidad_operativa'] >= 0 ? 'Ganó ' : 'Perdió ') . $pesos(abs($m['utilidad_operativa'])) . " (margen {$margen})",
            'vendió ' . $pesos($m['ventas']),
        ];
        $alertas = array_values(array_filter($ind['semaforo'], fn ($s) => in_array($s['nivel'], ['alerta', 'atencion'], true)));
        if ($alertas) {
            $partes[] = 'Ojo: ' . implode('; ', array_map(fn ($a) => mb_strtolower($a['titulo']), array_slice($alertas, 0, 3)));
        }
        $quincena = array_filter(CalendarioPagos::proximos(15), fn ($p) => ! $p['vencido']);
        if ($quincena) {
            $partes[] = 'En la quincena se pagan ' . $pesos(array_sum(array_column($quincena, 'monto')));
        }
        $partes[] = 'Cuando lo revises, ciérralo en Finanzas.';

        $titulo = Periodo::nombre($mes) . ' cerró ' . ($m['utilidad_operativa'] >= 0 ? 'con utilidad' : 'en pérdida');
        $mensaje = implode('. ', $partes) . '.';

        $quienes = Usuario::where('activo', true)->where('rol', 'supervisor')->where('acceso_finanzas', true)->pluck('id');
        foreach ($quienes as $id) {
            NotificacionService::crear('finanzas', $titulo, $mensaje, ['ruta' => 'finanzas', 'pestana' => 'resumen', 'mes' => $mes], $id);
        }
    }
}
