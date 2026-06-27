<?php

namespace App\Mail;

use App\Models\Orden;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CotizacionMail extends Mailable
{
    use Queueable, SerializesModels;

    private ?Orden $ordenCache = null;

    public function __construct(public readonly int $ordenId) {}

    public function envelope(): Envelope
    {
        $num = $this->cargarOrden()->numero_orden ?? $this->ordenId;
        return new Envelope(
            subject: "Cotización Decasa — Orden #{$num}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.cotizacion',
            with: ['orden' => $this->cargarOrden()],
        );
    }

    public function attachments(): array
    {
        ini_set('memory_limit', '512M');
        set_time_limit(120);

        try {
            $orden = $this->cargarOrden();

            $firmaCliente  = $this->urlToBase64($orden->firma_url);
            $firmaVendedor = $this->urlToBase64($orden->vendedor?->firma_url);
            $logoBase64    = $this->avifToPngBase64(public_path('img/logo.avif'));
            $bocetosBase64 = [];
            foreach ($orden->items as $item) {
                if ($item->es_personalizado && $item->boceto_url) {
                    $bocetosBase64[$item->id] = $this->urlToBase64($item->boceto_url);
                }
            }

            $pdf = Pdf::loadView('pdf.orden', compact('orden', 'firmaCliente', 'firmaVendedor', 'logoBase64', 'bocetosBase64'));
            $pdf->setPaper('letter');

            $num = $orden->numero_orden ?? $this->ordenId;
            return [
                Attachment::fromData(
                    fn () => $pdf->output(),
                    "cotizacion-decasa-{$num}.pdf"
                )->withMime('application/pdf'),
            ];
        } catch (\Throwable $e) {
            \Log::error('CotizacionMail: fallo al generar PDF', [
                'orden_id' => $this->ordenId,
                'error'    => $e->getMessage(),
                'file'     => $e->getFile(),
                'line'     => $e->getLine(),
            ]);
            return [];
        }
    }

    private function cargarOrden(): Orden
    {
        if ($this->ordenCache) return $this->ordenCache;

        $orden = Orden::with([
            'cliente',
            'tienda:id,nombre',
            'vendedor:id,nombre,firma_url',
            'items.producto:id,nombre,categoria',
            'pagos',
        ])->findOrFail($this->ordenId);

        $orden->total_pagado      = $orden->totalPagado();
        $orden->saldo_pendiente   = $orden->saldoPendiente();
        $orden->porcentaje_pagado = $orden->valor_total > 0
            ? min(100, round(($orden->total_pagado / $orden->valor_total) * 100))
            : 0;

        return $this->ordenCache = $orden;
    }

    private function urlToBase64(?string $url): ?string
    {
        if (! $url) return null;
        try {
            $bytes = file_get_contents($url, false, stream_context_create([
                'http' => ['timeout' => 5],
                'ssl'  => ['verify_peer' => false],
            ]));
            return $bytes ? 'data:image/png;base64,' . base64_encode($bytes) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function avifToPngBase64(string $path): ?string
    {
        if (! file_exists($path)) return null;
        try {
            $img  = imagecreatefromavif($path);
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
