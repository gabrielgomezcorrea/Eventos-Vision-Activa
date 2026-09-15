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

class PagoAprobado extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $orden) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Pago confirmado · inscripción {$this->orden->number}");
    }

    public function content(): Content
    {
        // Se emite un enlace nuevo: el que trajo al cliente hasta aquí pudo
        // vencer antes de que quiera volver a imprimir las credenciales.
        [, $token] = MagicLink::emitir($this->orden->event, $this->orden->responsible_email, $this->orden);

        return new Content(
            markdown: 'mail.pago-aprobado',
            with: [
                'orden' => $this->orden,
                'event' => $this->orden->event,
                'urlCredenciales' => route('inscripcion.credenciales', ['token' => $token]),
            ],
        );
    }
}
