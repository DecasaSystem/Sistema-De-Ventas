<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Subir a Cloudinary algo que no llegó como archivo: la firma que el cliente
 * dibuja en la página pública, que no tiene sesión para usar /upload/foto.
 * Misma cuenta y misma forma de firmar la petición que UploadController.
 */
class Cloudinary
{
    /** Devuelve la URL https, o null si Cloudinary no la recibió. */
    public static function subirContenido(string $contenido, string $nombre, string $carpeta): ?string
    {
        $cloudName = config('services.cloudinary.cloud_name');
        $apiKey    = config('services.cloudinary.api_key');
        $apiSecret = config('services.cloudinary.api_secret');
        $timestamp = time();
        $folder    = 'decasa/' . $carpeta;

        $signature = sha1("folder={$folder}&timestamp={$timestamp}{$apiSecret}");

        $response = Http::attach('file', $contenido, $nombre)
            ->post("https://api.cloudinary.com/v1_1/{$cloudName}/image/upload", [
                'api_key'   => $apiKey,
                'timestamp' => $timestamp,
                'signature' => $signature,
                'folder'    => $folder,
            ]);

        return $response->ok() ? $response->json('secure_url') : null;
    }
}
