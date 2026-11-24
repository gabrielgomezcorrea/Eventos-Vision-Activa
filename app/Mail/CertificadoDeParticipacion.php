<?php

namespace App\Mail;

use App\Models\Certificate;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The participant's certificate, attached. Not queued on its own: the
 * EnviarCertificado job sends it in its turn and records the result.
 */
class CertificadoDeParticipacion extends Mailable
{
    public function __construct(public Certificate $certificado) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Certificado de participación – '.$this->certificado->event->name);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.certificado-de-participacion',
            with: [
                'evento' => $this->certificado->event->loadMissing('sessions'),
                'participante' => $this->certificado->participant,
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromStorageDisk($this->certificado->disk, $this->certificado->path)
                ->as($this->certificado->nombreDelAdjunto())
                ->withMime('application/pdf'),
        ];
    }
}
