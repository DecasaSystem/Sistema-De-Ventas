<?php

namespace App\Support;

/**
 * Conversión de imágenes a base64 para DomPDF, que no resuelve URLs remotas
 * ni formatos AVIF por sí solo.
 */
trait ConvierteImagenesPdf
{
    protected function urlToBase64(?string $url): ?string
    {
        if (! $url) return null;

        // Solo permitir URLs de dominios de almacenamiento confiables
        $dominiosPermitidos = ['res.cloudinary.com', 'cloudinary.com', 'amazonaws.com', 's3.'];
        $host = parse_url($url, PHP_URL_HOST) ?? '';
        $esPermitida = collect($dominiosPermitidos)->contains(fn($d) => str_contains($host, $d));

        if (! $esPermitida) {
            \Log::warning('urlToBase64: URL de dominio no permitido', ['url' => $url]);
            return null;
        }

        try {
            $bytes = file_get_contents($url, false, stream_context_create([
                'http' => ['timeout' => 5],
                'ssl'  => ['verify_peer' => true],
            ]));
            return $bytes ? 'data:image/png;base64,' . base64_encode($bytes) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function avifToPngBase64(string $path): ?string
    {
        if (! file_exists($path)) return null;
        try {
            $img = imagecreatefromavif($path);
            ob_start();
            imagepng($img);
            $data = ob_get_clean();
            imagedestroy($img);
            return 'data:image/png;base64,' . base64_encode($data);
        } catch (\Throwable) {
            return null;
        }
    }
}
