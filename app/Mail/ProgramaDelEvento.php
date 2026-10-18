<?php

namespace App\Mail;

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
            markdown: 'mail.programa-del-evento',
            with: [
                'solicitud' => $this->solicitud,
                'event' => $this->solicitud->event,
                'enlace' => $this->urlDeInscripcion ?? route('inscripcion.inicio', $this->solicitud->event),
            ],
        );
    }

    /**
     * Solo el programa más reciente del evento, y solo si cabe en el correo.
     * Los archivos antiguos siguen disponibles en el portal, pero no viajan.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $programa = $this->solicitud->event->attachments->last();

        return $programa?->cabeEnElCorreo()
            ? [Attachment::fromStorageDisk($programa->disk, $programa->path)->as($programa->original_name)]
            : [];
    }

    public function build(): self
    {
        return $this->afterCommit();
    }
}
