<?php

namespace App\Mail;

use App\Models\Event;
use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Invitación personal: un enlace de un solo uso para inscribirse sin pagar. */
class InvitacionAlEvento extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Event $event;

    public function __construct(public Invitation $invitacion, public string $url)
    {
        $this->event = $invitacion->event;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Estás invitado a '.$this->event->name);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.invitacion-al-evento');
    }
}
