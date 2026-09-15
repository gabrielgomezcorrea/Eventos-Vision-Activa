<?php

namespace App\Mail;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Credencial enviada directamente al participante. Es best-effort: se envía
 * solo si tiene un correo válido y su fallo no afecta a la orden.
 */
class CredencialParticipante extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Ticket $ticket) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tu credencial para '.$this->ticket->event->name);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.credencial-participante',
            with: [
                'ticket' => $this->ticket,
                'evento' => $this->ticket->event,
                'participante' => $this->ticket->participant,
            ],
        );
    }
}
