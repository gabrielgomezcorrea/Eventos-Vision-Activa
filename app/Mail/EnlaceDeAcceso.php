<?php

namespace App\Mail;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Enlace seguro para iniciar o retomar una inscripción, sin contraseña.
 */
class EnlaceDeAcceso extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Event $event,
        public string $url,
        public ?string $numeroDeOrden = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->numeroDeOrden
                ? "Continúa tu inscripción {$this->numeroDeOrden}"
                : 'Enlace para tu inscripción a '.$this->event->name,
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.enlace-de-acceso');
    }
}
