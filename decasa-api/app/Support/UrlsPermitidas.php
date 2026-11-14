<?php

namespace App\Support;

/**
 * Direcciones que el servidor puede descargar por su cuenta.
 *
 * Para meter una foto en un PDF, el servidor la descarga. Si aceptara
 * cualquier dirección, una "foto" escrita a mano lo mandaba a leer direcciones
 * internas o archivos del propio servidor. Antes la revisión era
 * `str_contains`, que dejaba pasar `s3.cualquier-sitio.com` o
 * `res.cloudinary.com.otro.com`, y el correo de la cotización no revisaba
 * nada (revisión de seguridad, 2026-11-14).
 */
class UrlsPermitidas
{
    private const DOMINIOS_DE_FOTOS = ['cloudinary.com', 'amazonaws.com'];

    /** Solo https y solo de donde se guardan las fotos (Cloudinary, S3). */
    public static function esFoto(string $url): bool
    {
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') return false;
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') return false;

        foreach (self::DOMINIOS_DE_FOTOS as $dominio) {
            if ($host === $dominio || str_ends_with($host, '.' . $dominio)) return true;
        }
        return false;
    }

    /**
     * El servicio de avisos push del navegador. El servidor le manda cada
     * aviso a la dirección que registró el aparato; sin esta lista cualquier
     * sesión podía registrar una dirección propia o interna y el servidor le
     * hacía peticiones.
     */
    public static function esServicioPush(string $url): bool
    {
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') return false;
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        foreach (['fcm.googleapis.com', 'android.googleapis.com', 'updates.push.services.mozilla.com',
                  'push.services.mozilla.com', 'notify.windows.com', 'push.apple.com'] as $dominio) {
            if ($host === $dominio || str_ends_with($host, '.' . $dominio)) return true;
        }
        return false;
    }
}
