<?php

namespace App\Mail;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Aviso interno: un cliente subió un comprobante que Contabilidad debe revisar. No va al cliente. */
class AvisoComprobantePorRevisar extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Payment $pago) {}

    public function envelope(): Envelope
    {
        $orden = $this->pago->order;

        return new Envelope(subject: "Comprobante por revisar: {$orden->number} · {$orden->event->name}");
    }

    public function content(): Content
    {
        $orden = $this->pago->order;

        return new Content(
            markdown: 'mail.aviso-comprobante-por-revisar',
            with: [
                'pago' => $this->pago,
                'colegio' => $orden->nombreDelColegio() ?? $orden->responsableNombreCompleto(),
                'url' => route('comprobantes.show', $this->pago),
            ],
        );
    }
}
