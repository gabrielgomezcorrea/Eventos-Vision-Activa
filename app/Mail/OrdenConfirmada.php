<?php

namespace App\Mail;

use App\Models\MagicLink;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Confirmación de la inscripción con las instrucciones de pago.
 */
class OrdenConfirmada extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $orden) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Inscripción {$this->orden->number} confirmada · instrucciones de pago",
        );
    }

    public function content(): Content
    {
        // Se emite un enlace nuevo para el seguimiento: el del borrador pudo
        // haber vencido antes de que el cliente vuelva a mirar su orden.
        [, $token] = MagicLink::emitir($this->orden->event, $this->orden->responsible_email, $this->orden);

        return new Content(
            markdown: 'mail.orden-confirmada',
            with: [
                'orden' => $this->orden,
                'event' => $this->orden->event,
                'urlSeguimiento' => route('inscripcion.estado', ['token' => $token]),
            ],
        );
    }
}
