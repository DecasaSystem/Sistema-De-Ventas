<?php

use App\Jobs\AlertarRetrasoProduccion;
use App\Jobs\AlertarRutasAtrasadas;
use App\Jobs\AvisarCotizacionesPorVencer;
use App\Jobs\AvisarRevisionesEncargos;
use App\Jobs\RecordatoriosCitas;
use Illuminate\Support\Facades\Schedule;

// ── Scheduler ─────────────────────────────────────────────────────────────────
// Corre todos los días a las 7:00 AM (hora Bogotá)
// Para activar: agregar al crontab del servidor →
//   * * * * * cd /ruta/proyecto && php artisan schedule:run >> /dev/null 2>&1

Schedule::job(new AlertarRetrasoProduccion())
    ->dailyAt('07:00')
    ->timezone('America/Bogota')
    ->name('alertar-retraso-produccion')
    ->withoutOverlapping();

Schedule::job(new AlertarRutasAtrasadas())
    ->dailyAt('07:30')
    ->timezone('America/Bogota')
    ->name('alertar-rutas-atrasadas')
    ->withoutOverlapping();

Schedule::job(new RecordatoriosCitas())
    ->dailyAt('08:00')
    ->timezone('America/Bogota')
    ->name('recordatorios-citas')
    ->withoutOverlapping();

Schedule::job(new AvisarCotizacionesPorVencer())
    ->dailyAt('08:30')
    ->timezone('America/Bogota')
    ->name('avisar-cotizaciones-por-vencer')
    ->withoutOverlapping();

// Encargos: a quién le toca revista. Diario, para que el aviso llegue el DÍA
// que toca y no cuando cuadre; el propio job se guarda de repetir a los
// atrasados todos los días (esos se recuerdan los lunes).
Schedule::job(new AvisarRevisionesEncargos())
    ->dailyAt('08:45')
    ->timezone('America/Bogota')
    ->name('avisar-revisiones-encargos')
    ->withoutOverlapping();

// La nómina ya no necesita un job: los ciclos de pago se calculan del
// calendario cuando se abre la pantalla (App\Services\CicloNomina), así que
// no hay períodos que generar de madrugada.

// De madrugada, cuando nadie está vendiendo, para no competir por la conexión.
Schedule::command('respaldo:base')
    ->dailyAt('03:00')
    ->timezone('America/Bogota')
    ->name('respaldo-base-datos')
    ->withoutOverlapping()
    ->emailOutputOnFailure(config('mail.from.address'));

// ── Comando manual (útil en desarrollo y soporte) ─────────────────────────────
// Uso: php artisan produccion:revisar-retrasos

Artisan::command('produccion:revisar-retrasos', function () {
    $this->info('Ejecutando revisión de retrasos de producción...');

    $job = new AlertarRetrasoProduccion();
    $job->handle();

    $this->info('Revisión completada. Ver logs en storage/logs/laravel.log');
})->purpose('Marca como retrasadas las producciones vencidas y alerta las que vencen en ≤ 3 días');
