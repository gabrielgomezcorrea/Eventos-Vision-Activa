<?php

namespace App\Mail;

use App\Models\ProgramRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Aviso interno: alguien pidió el programa de un evento. No va al cliente. */
class AvisoNuevaSolicitud extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public ProgramRequest $solicitud) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Nueva solicitud: {$this->solicitud->nombre_completo} · {$this->solicitud->event->name}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.aviso-nueva-solicitud',
            with: [
                'solicitud' => $this->solicitud,
                'evento' => $this->solicitud->event,
                'url' => route('solicitudes.show', $this->solicitud),
            ],
        );
    }
}
