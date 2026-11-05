<?php

namespace App\Mail;

use App\Models\EventAttachment;
use App\Models\ProgramRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Envía el programa del evento a quien lo solicitó desde el formulario público.
 */
class ProgramaDelEvento extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Evento cuyo correo lleva las dos opciones de inscripción y los valores
     * del código CMEI-2026. Ese texto está escrito a mano (Gabriel,
     * 05/11/2026), así que solo sale para este evento: en otro mandaría
     * precios y un código que no le corresponden.
     */
    public const SLUG_CON_OPCIONES = 'seminario-liderazgo-noviembre-2026';

    /**
     * @param  string|null  $urlDeInscripcion  Enlace que deja al cliente dentro de su
     *                                         inscripción sin escribir el correo de nuevo.
     */
    public function __construct(public ProgramRequest $solicitud, public ?string $urlDeInscripcion = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Programa de '.$this->solicitud->event->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: $this->solicitud->event->slug === self::SLUG_CON_OPCIONES
                ? 'mail.programa-del-evento-con-opciones'
                : 'mail.programa-del-evento',
            with: [
                'solicitud' => $this->solicitud,
                'event' => $this->solicitud->event,
                'enlace' => $this->urlDeInscripcion ?? route('inscripcion.inicio', $this->solicitud->event),
            ],
        );
    }

    /**
     * Todos los archivos del evento mientras quepan en el correo. Los que no
     * caben siguen disponibles en el portal, pero no viajan.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            fn (EventAttachment $a): Attachment => Attachment::fromStorageDisk($a->disk, $a->path)->as($a->original_name),
            EventAttachment::paraElCorreo($this->solicitud->event->attachments),
        );
    }

    public function build(): self
    {
        return $this->afterCommit();
    }
}
