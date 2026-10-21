<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Los enlaces de firma que salieron con la dirección de la API (Render) en
// vez de la app (2026-10-21): llevan al cliente a la página de verdad.
// Ver AnexoGarantiaController::baseApp.
Route::get('/firmar/{token}', function (string $token) {
    $app = config('app.frontend_url') ?: \App\Http\Controllers\AnexoGarantiaController::APP_POR_DEFECTO;

    return redirect()->away(rtrim($app, '/') . '/firmar/' . $token, 302);
})->where('token', '[A-Za-z0-9]{20,64}');
