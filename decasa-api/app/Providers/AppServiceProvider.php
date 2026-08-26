<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Mail::extend('brevo', function (array $config) {
            $factory = new BrevoTransportFactory();
            return $factory->create(
                new Dsn('brevo+api', 'default', $config['key'] ?? '')
            );
        });

        /*
         * Un techo para toda la API.
         *
         * Solo siete rutas de casi trescientas tenían límite, así que con un
         * token válido se podía barrer la base entera a la velocidad que
         * diera la red — clientes, órdenes, precios— sin que nada lo frenara.
         *
         * 300 por minuto es muy por encima de lo que hace una persona usando
         * la app (la pantalla más pesada dispara ocho o diez peticiones), así
         * que nadie lo va a notar; lo que corta es el barrido automático.
         *
         * Por usuario cuando hay sesión, y por IP cuando no: si fuera solo por
         * IP, toda una tienda tras el mismo router compartiría el cupo.
         */
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(300)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
