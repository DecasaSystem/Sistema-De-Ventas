<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    /**
     * Carpetas que también reciben PDF: el comprobante de pago y el anexo de
     * garantías firmado en papel (los dos van a "facturas" desde Nueva orden).
     * Pedido del usuario (2026-10-28): muchos clientes mandan el comprobante
     * de la transferencia en PDF desde la app del banco, y el anexo escaneado
     * llega igual. "borradores" porque la orden a medio hacer guarda en la
     * cuenta lo que ya se adjuntó, también un PDF.
     */
    public const CARPETAS_CON_PDF = ['facturas', 'comprobantes', 'anexos', 'borradores'];

    /**
     * POST /api/upload/foto
     *
     * Recibe un archivo de imagen, lo firma y lo sube a Cloudinary.
     * Devuelve la URL segura (https) para guardarla en foto_url del producto.
     */
    public function foto(Request $request)
    {
        $carpeta  = $request->input('folder', 'productos');
        $aceptaPdf = in_array($carpeta, self::CARPETAS_CON_PDF, true);

        // Cada carpeta que usa la app tiene que estar aquí: el chat de la
        // orden, las devoluciones y "regresar al taller" mandaban la suya y se
        // les rechazaba siempre, con cualquier foto. Y HEIC/HEIF entran: es lo
        // que saca la cámara del iPhone cuando el navegador no alcanza a
        // convertirla, y Cloudinary la recibe igual.
        $request->validate([
            'foto'  => 'required|file|mimes:jpg,jpeg,png,gif,webp,bmp,heic,heif' . ($aceptaPdf ? ',pdf' : '') . '|max:10240',
            'folder' => 'nullable|string|in:productos,facturas,firmas,bocetos,comprobantes,telas,anexos,compras,catalogos,modulos,chat-ordenes,devoluciones,garantias,produccion,soportes,gastos,borradores',
        ], [
            'foto.max'   => $aceptaPdf ? 'El archivo pesa más de 10 MB.' : 'La foto pesa más de 10 MB.',
            'foto.mimes' => $aceptaPdf
                ? 'Ese archivo no se puede subir: tiene que ser una foto (JPG, PNG, WEBP o HEIC) o un PDF.'
                : 'Ese archivo no es una foto que se pueda subir (JPG, PNG, WEBP o HEIC).',
        ]);

        $cloudName = config('services.cloudinary.cloud_name');
        $apiKey    = config('services.cloudinary.api_key');
        $apiSecret = config('services.cloudinary.api_secret');
        $timestamp = time();
        $folder    = 'decasa/' . $carpeta;

        $file  = $request->file('foto');
        $esPdf = $aceptaPdf && strtolower((string) $file->guessExtension()) === 'pdf';

        // Un PDF va como archivo "raw": Cloudinary no deja ver los PDF subidos
        // como imagen en las cuentas que no lo tienen activado (devuelve 401).
        // El nombre lleva ".pdf" para que el enlace termine así: la app sabe
        // por el enlace que es un PDF y lo abre en otra pestaña, no en el
        // visor de fotos.
        $params = ['folder' => $folder, 'timestamp' => $timestamp];
        if ($esPdf) {
            $params['public_id'] = Str::lower(Str::random(20)) . '.pdf';
        }

        // Firma requerida por Cloudinary para uploads autenticados: los
        // parámetros en orden alfabético.
        ksort($params);
        $signature = sha1(collect($params)->map(fn ($v, $k) => "{$k}={$v}")->implode('&') . $apiSecret);

        $response = Http::attach(
            'file',
            file_get_contents($file->getRealPath()),
            $file->getClientOriginalName()
        )->post("https://api.cloudinary.com/v1_1/{$cloudName}/" . ($esPdf ? 'raw' : 'image') . '/upload', $params + [
            'api_key'   => $apiKey,
            'signature' => $signature,
        ]);

        if (! $response->ok()) {
            $detalle = $response->json('error.message') ?? $response->body();
            return response()->json(
                ['message' => "Error Cloudinary: {$detalle}"],
                502
            );
        }

        return response()->json(['url' => $response->json('secure_url')]);
    }
}
