<?php

namespace App\Mail;

use App\Models\Event;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Enlace seguro para iniciar o retomar una inscripción, sin contraseña.
 *
 * Con un conjunto de dos o más colegios ya confirmados, $colegios trae la
 * lista y el enlace es el del conjunto: un solo correo y un solo enlace,
 * aunque sean siete colegios.
 */
class EnlaceDeAcceso extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param  Collection<int, Order>|null  $colegios */
    public function __construct(
        public Event $event,
        public string $url,
        public ?string $numeroDeOrden = null,
        public ?string $nombre = null,
        public ?Collection $colegios = null,
    ) {}

    public function envelope(): Envelope
    {
        if ($this->colegios && $this->colegios->count() > 1) {
            return new Envelope(subject: "Continúa el pago de tu inscripción en {$this->colegios->count()} establecimientos");
        }

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
