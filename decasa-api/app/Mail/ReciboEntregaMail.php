<?php

namespace App\Mail;

use App\Models\DespachoItem;
use App\Services\ReciboEntrega;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * El recibo de entrega y pago, con el PDF adjunto.
 *
 * El PDF va adjunto (Brevo acepta .pdf) y además el enlace: hay correos que
 * esconden los adjuntos en el teléfono.
 */
class ReciboEntregaMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly int $despachoItemId,
        public readonly ?string $enlace,
        private readonly string $pdf,
        private readonly string $nombreArchivo,
    ) {}

    private function item(): ?DespachoItem
    {
        return DespachoItem::with('orden.cliente:id,nombre')->find($this->despachoItemId);
    }

    public function envelope(): Envelope
    {
        $item = $this->item();
        $ref  = $item?->reciboReferencia();

        return new Envelope(subject: $ref
            ? "Decasa — Recibo de entrega y pago {$ref}"
            : 'Decasa — Acta de entrega de tu pedido');
    }

    public function content(): Content
    {
        $item    = $this->item();
        $orden   = $item?->orden;
        $cuentas = $item && $orden ? ReciboEntrega::cuentas($orden, $item) : null;

        return new Content(
            view: 'emails.recibo_entrega',
            with: [
                'cliente'    => $orden?->cliente?->nombre,
                'referencia' => $item?->reciboReferencia(),
                'orden'      => $orden?->referencia,
                'pagadoHoy'  => $cuentas['hoy'] ?? 0,
                'saldo'      => $cuentas['saldo'] ?? null,
                'enlace'     => $this->enlace,
            ],
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdf, $this->nombreArchivo)->withMime('application/pdf'),
        ];
    }
}
