<?php

use App\Jobs\AlertarRetrasoProduccion;
use Illuminate\Support\Facades\Schedule;

// ── Scheduler ─────────────────────────────────────────────────────────────────
// Corre todos los días a las 7:00 AM (hora Bogotá)
// Para activar: agregar al crontab del servidor →
//   * * * * * cd /ruta/proyecto && php artisan schedule:run >> /dev/null 2>&1

Schedule::job(new AlertarRetrasoProduccion())
    ->dailyAt('07:00')
    ->name('alertar-retraso-produccion')
    ->withoutOverlapping();

// ── Comando manual (útil en desarrollo y soporte) ─────────────────────────────
// Uso: php artisan produccion:revisar-retrasos

Artisan::command('produccion:revisar-retrasos', function () {
    $this->info('Ejecutando revisión de retrasos de producción...');

    $job = new AlertarRetrasoProduccion();
    $job->handle();

    $this->info('Revisión completada. Ver logs en storage/logs/laravel.log');
})->purpose('Marca como retrasadas las producciones vencidas y alerta las que vencen en ≤ 3 días');
