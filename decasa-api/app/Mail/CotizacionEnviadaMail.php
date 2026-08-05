

<?php

namespace App\Mail;

use App\Models\Orden;
use App\Support\ConvierteImagenesPdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * La cotización del módulo propio (COT-N) enviada al cliente.
 *
 * No es la misma que CotizacionMail: aquella manda el PDF de una orden ya
 * confirmada, con firma y saldo. Esta manda el documento de la cotización, que
 * no tiene firma del cliente ni saldo y sí lleva la fecha hasta la que vale.
 */
class CotizacionEnviadaMail extends Mailable
{
    use Queueable, SerializesModels, ConvierteImagenesPdf;

    private ?Orden $cache = null;

    public function __construct(public readonly int $cotizacionId) {}

    public function envelope(): Envelope
    {
        $c = $this->cargar();
        return new Envelope(
            subject: 'Cotización Decasa — ' . ($c->cotizacion_ref ?? "#{$this->cotizacionId}"),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.cotizacion_enviada',
            with: ['cotizacion' => $this->cargar()],
        );
    }

    public function attachments(): array
    {
        ini_set('memory_limit', '512M');
        set_time_limit(120);

        try {
            $cotizacion    = $this->cargar();
            $firmaVendedor = $this->urlToBase64($cotizacion->vendedor?->firma_url);
            $logoBase64    = $this->avifToPngBase64(public_path('img/logo.avif'));

            $pdf = Pdf::loadView('pdf.cotizacion', compact('cotizacion', 'firmaVendedor', 'logoBase64'));
            $pdf->setPaper('letter');

            $nombre = strtolower($cotizacion->cotizacion_ref ?? ('cotizacion-' . $this->cotizacionId));

            return [
                Attachment::fromData(fn () => $pdf->output(), $nombre . '.pdf')
                    ->withMime('application/pdf'),
            ];
        } catch (\Throwable $e) {
            // El correo sale igual con el resumen: mejor eso que nada
            \Log::error('CotizacionEnviadaMail: fallo al generar el PDF', [
                'cotizacion_id' => $this->cotizacionId,
                'error'         => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function cargar(): Orden
    {
        if ($this->cache) return $this->cache;

        return $this->cache = Orden::cotizaciones()
            ->with([
                'cliente',
                'tienda:id,nombre',
                'vendedor:id,nombre,firma_url',
                'items.producto:id,nombre,categoria',
            ])
            ->findOrFail($this->cotizacionId);
    }
}
