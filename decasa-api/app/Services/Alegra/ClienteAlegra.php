<?php

namespace App\Services\Alegra;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * El único lugar que habla con la API de Alegra (developer.alegra.com).
 *
 *  - Autenticación Basic con correo:token (config/services.php → alegra).
 *  - Límite de 150 peticiones por minuto: con un 429 espera lo que diga
 *    `X-Rate-Limit-Reset` (máximo 10 s) y lo intenta una vez más.
 *  - Si no hay respuesta (se cayó la red, se acabó el tiempo) lanza
 *    SinRespuestaDeAlegra: quien llama NO sabe si la factura se creó y tiene
 *    que verificar antes de volver a intentar (Alegra no tiene llave de
 *    idempotencia: reintentar a ciegas puede crear dos facturas).
 */
class ClienteAlegra
{
    public static function configurado(): bool
    {
        return (bool) config('services.alegra.email') && (bool) config('services.alegra.token');
    }

    public function get(string $ruta, array $query = []): RespuestaAlegra
    {
        return $this->pedir('get', $ruta, $query);
    }

    public function post(string $ruta, array $cuerpo = []): RespuestaAlegra
    {
        return $this->pedir('post', $ruta, $cuerpo);
    }

    public function delete(string $ruta): RespuestaAlegra
    {
        return $this->pedir('delete', $ruta);
    }

    // ── Lo que usa la facturación ───────────────────────────────────────────

    /** Contactos con esa identificación (filtro `identification` de /contacts). */
    public function buscarContactos(string $identificacion): array
    {
        $r = $this->get('contacts', ['identification' => $identificacion, 'limit' => 30]);

        return $r->ok && is_array($r->json) ? array_values(array_filter($r->json, 'is_array')) : [];
    }

    public function crearContacto(array $contacto): RespuestaAlegra
    {
        return $this->post('contacts', $contacto);
    }

    public function crearFactura(array $factura): RespuestaAlegra
    {
        return $this->post('invoices', $factura);
    }

    public function verFactura(string $id, array $campos = []): RespuestaAlegra
    {
        return $this->get("invoices/{$id}", $campos ? ['fields' => implode(',', $campos)] : []);
    }

    public function borrarFactura(string $id): RespuestaAlegra
    {
        return $this->delete("invoices/{$id}");
    }

    /** Facturas recientes de un cliente en una fecha (para encontrar una que quedó en el aire). */
    public function facturasDelCliente(string $contactoId, string $fecha): array
    {
        $r = $this->get('invoices', [
            'client_id' => $contactoId, 'date' => $fecha,
            'order_field' => 'id', 'order_direction' => 'DESC', 'limit' => 30,
        ]);

        return $r->ok && is_array($r->json) ? array_values(array_filter($r->json, 'is_array')) : [];
    }

    public function enviarPorCorreo(string $id, array $correos): RespuestaAlegra
    {
        return $this->post("invoices/{$id}/email", ['emails' => array_values($correos)]);
    }

    // ── Por dentro ──────────────────────────────────────────────────────────

    private function pedir(string $metodo, string $ruta, array $datos = [], bool $esReintento = false): RespuestaAlegra
    {
        if (! self::configurado()) {
            return new RespuestaAlegra(false, 0, null, 'Alegra no está conectado: faltan ALEGRA_EMAIL y ALEGRA_TOKEN en el servidor.');
        }

        $url = rtrim((string) config('services.alegra.url'), '/') . '/' . ltrim($ruta, '/');

        try {
            $http = Http::withBasicAuth((string) config('services.alegra.email'), (string) config('services.alegra.token'))
                ->acceptJson()
                ->timeout(max(5, (int) config('services.alegra.timeout', 60)));

            /** @var Response $res */
            $res = match ($metodo) {
                'get'    => $http->get($url, $datos),
                'post'   => $http->asJson()->post($url, $datos),
                'delete' => $http->delete($url),
            };
        } catch (ConnectionException $e) {
            Log::warning('[alegra] sin respuesta', ['ruta' => $ruta, 'error' => $e->getMessage()]);
            throw new SinRespuestaDeAlegra('Alegra no respondió: ' . $e->getMessage(), 0, $e);
        }

        if ($res->status() === 429 && ! $esReintento) {
            $espera = min(10, max(1, (int) $res->header('X-Rate-Limit-Reset')));
            sleep($espera);

            return $this->pedir($metodo, $ruta, $datos, true);
        }

        // Un 5xx en un POST tampoco dice si se creó o no.
        if ($res->serverError() && $metodo === 'post') {
            Log::warning('[alegra] error del servidor', ['ruta' => $ruta, 'status' => $res->status()]);
            throw new SinRespuestaDeAlegra("Alegra respondió {$res->status()} (error de su lado).");
        }

        $json = $res->json();

        return new RespuestaAlegra(
            $res->successful(),
            $res->status(),
            $json,
            $res->successful() ? null : self::mensajeDeError($json, $res->status()),
        );
    }

    /** El mensaje de Alegra, en lo posible en palabras. */
    public static function mensajeDeError($json, int $status): string
    {
        if (is_array($json)) {
            foreach (['message', 'error', 'msg'] as $k) {
                if (! empty($json[$k]) && is_string($json[$k])) {
                    $code = isset($json['code']) && is_scalar($json['code']) ? " (código {$json['code']})" : '';

                    return $json[$k] . $code;
                }
                if (! empty($json[$k]) && is_array($json[$k]) && is_string($json[$k]['message'] ?? null)) {
                    return $json[$k]['message'];
                }
            }
        }

        return match ($status) {
            401     => 'Alegra rechazó el correo o el token (401). Revisa ALEGRA_EMAIL y ALEGRA_TOKEN.',
            403     => 'El plan de Alegra no permite esto (403).',
            404     => 'Alegra no encontró ese documento (404).',
            429     => 'Demasiadas peticiones a Alegra (429). Intenta en un minuto.',
            default => "Alegra respondió {$status}.",
        };
    }
}
