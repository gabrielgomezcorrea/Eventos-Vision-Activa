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
     * Los documentos cargados en el evento, hasta tres.
     *
     * Un archivo que ya no está en disco se salta en silencio: un adjunto
     * perdido no puede impedir que salga el correo con el resto.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return $this->solicitud->event->attachments
            ->filter(fn (EventAttachment $a): bool => $a->existe())
            ->map(fn (EventAttachment $a): Attachment => Attachment::fromStorageDisk($a->disk, $a->path)
                ->as($a->original_name))
            ->values()
            ->all();
    }

    public function build(): self
    {
        return $this->afterCommit();
    }
}
