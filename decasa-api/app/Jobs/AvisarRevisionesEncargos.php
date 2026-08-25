<?php

namespace App\Jobs;

use App\Models\Usuario;
use App\Services\NotificacionService;
use App\Services\RevisionEncargos;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * "Hoy le toca revisión a Fulano".
 *
 * Sin esto el módulo solo sirve si alguien se acuerda de abrirlo, y el punto
 * entero era no depender de que alguien se acuerde.
 *
 * Corre todos los días para que el aviso llegue **el día** que toca, no
 * cuando cuadre. Pero avisar todos los días de los mismos atrasados
 * convierte la notificación en ruido y se descarta junto con las que sí
 * importan, así que los que ya venían vencidos se recuerdan una vez por
 * semana, los lunes.
 *
 * Va solo a quien hace los checks (`revisa_encargos`), no a todo el que puede
 * mirar el módulo: si le llega a diez personas, ninguna sabe que le hablan
 * a ella.
 */
class AvisarRevisionesEncargos implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(): void
    {
        $hoy    = Carbon::today();
        $esLunes = $hoy->isMonday();

        $vencen   = collect();   // les toca justo hoy
        $atrasados = collect();  // ya se pasó la fecha

        foreach (Usuario::where('lleva_encargos', true)->where('activo', true)->get() as $trabajador) {
            $estado = RevisionEncargos::estadoDe($trabajador, $hoy);

            if ($estado['dias_restantes'] === 0)      $vencen->push($trabajador);
            elseif ($estado['estado'] === 'vencida')  $atrasados->push($trabajador);
        }

        // Los atrasados solo se recuerdan los lunes; los de hoy, siempre.
        $avisar = $esLunes ? $vencen->merge($atrasados) : $vencen;

        if ($avisar->isEmpty()) {
            Log::info('[DECASA] AvisarRevisionesEncargos: nada que avisar hoy.');
            return;
        }

        $revisores = Usuario::where('revisa_encargos', true)->where('activo', true)->get();

        if ($revisores->isEmpty()) {
            Log::warning('[DECASA] Hay revisiones de encargos pendientes y nadie marcado como que hace los checks.');
            return;
        }

        $mensaje = $this->mensaje($vencen, $esLunes ? $atrasados : collect());

        foreach ($revisores as $revisor) {
            NotificacionService::crear(
                'encargo_revision',
                'Toca revisar encargos',
                $mensaje,
                ['pendientes' => $avisar->count()],
                $revisor->id,
            );
        }

        Log::info('[DECASA] AvisarRevisionesEncargos: ' . $avisar->count() . ' pendientes avisados a ' . $revisores->count() . ' revisor(es).');
    }

    /** Nombres si son pocos, cuenta si son muchos: leerlo tiene que costar un vistazo. */
    private function mensaje($vencen, $atrasados): string
    {
        $partes = [];

        if ($vencen->isNotEmpty()) {
            $partes[] = $vencen->count() === 1
                ? "Hoy le toca a {$vencen->first()->nombre}."
                : 'Hoy les toca a ' . $this->nombres($vencen) . '.';
        }

        if ($atrasados->isNotEmpty()) {
            $partes[] = $atrasados->count() === 1
                ? "{$atrasados->first()->nombre} sigue sin revisar."
                : $atrasados->count() . ' siguen sin revisar: ' . $this->nombres($atrasados) . '.';
        }

        return implode(' ', $partes);
    }

    private function nombres($gente): string
    {
        $primeros = $gente->take(3)->pluck('nombre')->implode(', ');
        $resto    = $gente->count() - min($gente->count(), 3);

        return $resto > 0 ? "{$primeros} y {$resto} más" : $primeros;
    }
}
