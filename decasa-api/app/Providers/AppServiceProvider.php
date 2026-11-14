<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Support\CachesDePeticion;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Sin el UPDATE de last_used_at en cada petición (ver el modelo).
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Una sesión de una cuenta desactivada no sirve (revisión de
        // seguridad, 2026-11-14). Antes, desactivar a alguien solo cerraba la
        // puerta del login: las sesiones que ya tenía abiertas seguían
        // entrando hasta que vencían (60 días). Se revisa en cada petición
        // porque el token se carga con su usuario de todos modos.
        Sanctum::authenticateAccessTokensUsing(function ($token, bool $esValido) {
            if (! $esValido) return false;
            $u = $token->tokenable;
            if (! $u) return false;
            // null = esquema de pruebas sin la columna: no se frena.
            if ($u->activo !== null && ! $u->activo) return false;
            if (! empty($u->no_usa_programa)) return false;
            return true;
        });

        // El trabajador de la cola vive horas: que un trabajo no herede lo que
        // el anterior dejó guardado (ver CachesDePeticion).
        Queue::before(fn () => CachesDePeticion::olvidarTodo());

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
