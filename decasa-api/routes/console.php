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
// El día 1: cómo cerró el mes anterior, al celular de quien ve Finanzas.
Schedule::job(new \App\Jobs\ResumenFinancieroMensual())
    ->monthlyOn(1, '07:30')
    ->timezone('America/Bogota')
    ->name('resumen-financiero-mensual')
    ->withoutOverlapping();

// Gastos fijos por vencer: a quien ve Finanzas, una vez al día con la lista.
Schedule::job(new \App\Jobs\AvisarGastosPorVencer())
    ->dailyAt('08:15')
    ->timezone('America/Bogota')
    ->name('avisar-gastos-por-vencer')
    ->withoutOverlapping();

Schedule::job(new AvisarRevisionesEncargos())
    ->dailyAt('08:45')
    ->timezone('America/Bogota')
    ->name('avisar-revisiones-encargos')
    ->withoutOverlapping();

// Comisiones: lo que hace el botón Recalcular, cada mañana. Así el día 20 las
// que quedan listas se marcan y el supervisor recibe el aviso sin que nadie
// tenga que acordarse de pulsar nada.
Artisan::command('comisiones:poner-al-dia', function () {
    $r = app(\App\Http\Controllers\ComisionController::class)->ponerAlDia();
    $this->info("Comisiones al día: {$r['actualizadas']} cambiaron de estado, {$r['notificadas']} avisos, {$r['revaluadas']} revaluadas.");
})->purpose('Pone al día las comisiones pendientes y avisa las que quedaron listas');

Schedule::command('comisiones:poner-al-dia')
    ->dailyAt('06:30')
    ->timezone('America/Bogota')
    ->name('comisiones-poner-al-dia')
    ->withoutOverlapping();

// El botón Recalcular solo deja el pedido: hacerlo dentro de la petición
// tardaba minutos y el proxy la cortaba con un 502 (2026-10-19). Se revisa
// cada minuto y corre aparte (en segundo plano, para no frenar las demás
// tareas mientras tanto). Sin pedido no hace nada.
Artisan::command('comisiones:recalcular-pedido', function () {
    $r = app(\App\Http\Controllers\ComisionController::class)->correrRecalculoPedido();
    if ($r) $this->info("Recalculado: {$r['actualizadas']} cambiaron de estado, {$r['revaluadas']} revaluadas.");
})->purpose('Corre el recálculo de comisiones que alguien pidió con el botón');

// Facturación electrónica sin revisión (Facturación electrónica → Ajustes →
// "El facturador revisa antes": apagado). De fábrica está prendido y esta
// tarea no hace nada. Solo factura lo que no tiene ninguna advertencia; lo
// demás se queda en "Por facturar" para que lo mire una persona.
Artisan::command('facturacion:emitir-pendientes', function () {
    if (! \App\Support\ReglasFacturacion::activo() || \App\Support\ReglasFacturacion::get('requiere_revision')) return;

    $emitir = app(\App\Services\Facturacion\EmitirFactura::class);
    $hechas = 0;
    foreach (\App\Services\Facturacion\QueSeFactura::pendientes()->take(20) as $p) {
        // Un intento que falló lo resuelve una persona, no la tarea.
        if ($p['factura'] && in_array($p['factura']->estado, ['rechazada', 'error', 'incierta'], true)) continue;
        try {
            $f = $emitir->facturar($p['orden'], $p['pago'], null, [], true);
            if (in_array($f->estado, ['emitida', 'pendiente_dian', 'borrador_alegra'], true)) $hechas++;
        } catch (\App\Services\Facturacion\NoSePuedeFacturar) {
            // Tiene advertencias o le falta algo: queda para el facturador.
        }
    }
    if ($hechas) $this->info("Facturadas: {$hechas}");
})->purpose('Factura sola lo pendiente cuando la revisión del facturador está apagada');

// Las facturas que quedaron "DIAN validando" o "sin confirmar" (Alegra no
// respondió) se ponen al día solas; antes había que tocar "Revisar en Alegra".
// Una incierta que sí está en Alegra se adopta (no se crea otra). Pocas por
// vuelta para no gastar el límite de 150 peticiones por minuto.
Artisan::command('facturacion:actualizar-estados', function () {
    if (! \App\Services\Alegra\ClienteAlegra::configurado()) return;
    $emitir = app(\App\Services\Facturacion\EmitirFactura::class);
    $al = 0;
    \App\Models\FacturaElectronica::whereIn('estado', ['pendiente_dian', 'incierta'])
        ->where('updated_at', '<', now()->subMinutes(5))
        ->orderBy('updated_at')->limit(15)->get()
        ->each(function ($f) use ($emitir, &$al) {
            try {
                $antes = $f->estado;
                $f = $emitir->actualizar($f);
                if ($f->estado !== $antes) $al++;
                // Que no vuelva a la cabeza de la fila si no cambió.
                if ($f->estado === $antes) $f->touch();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::info('[facturacion] no se pudo actualizar', ['id' => $f->id, 'error' => $e->getMessage()]);
            }
        });
    if ($al) $this->info("Facturas actualizadas: {$al}");
})->purpose('Pone al día las facturas en "DIAN validando" o sin confirmar');

// "La factura el mismo día de la entrega" (contador, 2026-11-13). Desde la hora
// de Ajustes (de fábrica 5:00 p. m., Bogotá) se le avisa UNA vez al día al
// facturador lo entregado que sigue sin facturar: lo de hoy y lo atrasado.
// Vacía la hora = no avisa. Si no queda nada, no manda nada.
Artisan::command('facturacion:avisar-sin-facturar', function () {
    $r = \App\Support\ReglasFacturacion::todo();
    $hora = trim((string) ($r['aviso_sin_facturar_hora'] ?? ''));
    if (! \App\Support\ReglasFacturacion::activo() || $r['momento'] !== 'al_entregar' || $hora === '') return;

    $ahora = now('America/Bogota');
    if ($ahora->format('H:i') < $hora) return;
    // Una vez al día, aunque la tarea corra cada 15 minutos.
    if (! \Illuminate\Support\Facades\Cache::add('facturacion:aviso-sin-facturar:' . $ahora->toDateString(), true, now()->addDays(2))) return;

    $hoy = 0;
    $atrasadas = 0;
    foreach (\App\Services\Facturacion\QueSeFactura::pendientes() as $p) {
        \App\Services\Facturacion\QueSeFactura::diasSinFacturar($p['orden'], $p['pago']) === 0 ? $hoy++ : $atrasadas++;
    }
    $total = $hoy + $atrasadas;
    if (! $total) return;

    $partes = [];
    if ($hoy) $partes[] = $hoy === 1 ? '1 entregada hoy' : "{$hoy} entregadas hoy";
    if ($atrasadas) $partes[] = $atrasadas === 1 ? '1 de días anteriores' : "{$atrasadas} de días anteriores";
    $titulo = $total === 1 ? 'Queda 1 orden sin facturar' : "Quedan {$total} órdenes sin facturar";

    foreach (\App\Services\AvisoFacturacion::destinatarios() as $d) {
        \App\Services\NotificacionService::crear(
            tipo:      'factura_electronica',
            titulo:    $titulo,
            mensaje:   ucfirst(implode(' y ', $partes)) . '. Se facturan el mismo día de la entrega.',
            datos:     ['sin_facturar' => $total, 'hoy' => $hoy, 'atrasadas' => $atrasadas],
            usuarioId: $d->id,
        );
    }
    $this->info("Aviso: {$total} sin facturar");
})->purpose('Avisa al facturador lo entregado que sigue sin factura electrónica');

Schedule::command('facturacion:avisar-sin-facturar')
    ->everyFifteenMinutes()
    ->name('facturacion-avisar-sin-facturar')
    ->withoutOverlapping(30)
    ->runInBackground();

Schedule::command('facturacion:actualizar-estados')
    ->everyTenMinutes()
    ->name('facturacion-actualizar-estados')
    ->withoutOverlapping(30)
    ->runInBackground();

Schedule::command('facturacion:emitir-pendientes')
    ->everyFiveMinutes()
    ->name('facturacion-emitir-pendientes')
    ->withoutOverlapping(30)
    ->runInBackground();

Schedule::command('comisiones:recalcular-pedido')
    ->everyMinute()
    ->name('comisiones-recalcular-pedido')
    ->withoutOverlapping(30)
    ->runInBackground();

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
