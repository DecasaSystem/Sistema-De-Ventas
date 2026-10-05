<?php

namespace App\Mail;

use App\Models\AnexoGarantia;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** El enlace para que el cliente lea y firme el anexo de garantías. */
class AnexoParaFirmarMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly int $anexoId, public readonly string $enlace) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Decasa — Revisa y firma tu pedido');
    }

    public function content(): Content
    {
        $anexo = AnexoGarantia::with('cliente:id,nombre', 'vendedor:id,nombre')->find($this->anexoId);

        return new Content(
            view: 'emails.anexo_para_firmar',
            with: [
                'cliente'  => $anexo?->cliente?->nombre,
                'vendedor' => $anexo?->vendedor?->nombre,
                'enlace'   => $this->enlace,
                'total'    => $anexo?->resumen['total'] ?? null,
            ],
        );
    }
}
