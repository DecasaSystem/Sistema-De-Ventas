<?php

namespace App\Services;

use App\Models\PushSubscription;
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

class PushService
{
    public static function enviarAUsuario(int $usuarioId, string $titulo, string $cuerpo, array $datos = []): void
    {
        $suscripciones = PushSubscription::where('usuario_id', $usuarioId)->get();
        if ($suscripciones->isEmpty()) return;

        $auth = [
            'VAPID' => [
                'subject'    => config('app.url'),
                'publicKey'  => config('app.vapid_public_key'),
                'privateKey' => config('app.vapid_private_key'),
            ],
        ];

        $webPush = new WebPush($auth);

        $payload = json_encode([
            'title' => $titulo,
            'body'  => $cuerpo,
            'datos' => $datos,
        ]);

        $expiradas = [];

        foreach ($suscripciones as $sub) {
            $subscription = Subscription::create([
                'endpoint'        => $sub->endpoint,
                'keys'            => [
                    'p256dh' => $sub->p256dh,
                    'auth'   => $sub->auth_token,
                ],
            ]);

            $webPush->queueNotification($subscription, $payload);
        }

        foreach ($webPush->flush() as $report) {
            if ($report->isSubscriptionExpired()) {
                $expiradas[] = $report->getRequest()->getUri()->__toString();
            }
        }

        if (!empty($expiradas)) {
            PushSubscription::whereIn('endpoint', $expiradas)->delete();
        }
    }
}
