<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class UploadController extends Controller
{
    /**
     * POST /api/upload/foto
     *
     * Recibe un archivo de imagen, lo firma y lo sube a Cloudinary.
     * Devuelve la URL segura (https) para guardarla en foto_url del producto.
     */
    public function foto(Request $request)
    {
        // Cada carpeta que usa la app tiene que estar aquí: el chat de la
        // orden, las devoluciones y "regresar al taller" mandaban la suya y se
        // les rechazaba siempre, con cualquier foto. Y HEIC/HEIF entran: es lo
        // que saca la cámara del iPhone cuando el navegador no alcanza a
        // convertirla, y Cloudinary la recibe igual.
        $request->validate([
            'foto'  => 'required|file|mimes:jpg,jpeg,png,gif,webp,bmp,heic,heif|max:10240',
            'folder' => 'nullable|string|in:productos,facturas,firmas,bocetos,comprobantes,telas,anexos,compras,catalogos,modulos,chat-ordenes,devoluciones,produccion',
        ], [
            'foto.max'   => 'La foto pesa más de 10 MB.',
            'foto.mimes' => 'Ese archivo no es una foto que se pueda subir (JPG, PNG, WEBP o HEIC).',
        ]);

        $cloudName = config('services.cloudinary.cloud_name');
        $apiKey    = config('services.cloudinary.api_key');
        $apiSecret = config('services.cloudinary.api_secret');
        $timestamp = time();
        $folder    = 'decasa/' . ($request->input('folder', 'productos'));

        // Firma requerida por Cloudinary para uploads autenticados
        $signature = sha1("folder={$folder}&timestamp={$timestamp}{$apiSecret}");

        $file = $request->file('foto');

        $response = Http::attach(
            'file',
            file_get_contents($file->getRealPath()),
            $file->getClientOriginalName()
        )->post("https://api.cloudinary.com/v1_1/{$cloudName}/image/upload", [
            'api_key'   => $apiKey,
            'timestamp' => $timestamp,
            'signature' => $signature,
            'folder'    => $folder,
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
