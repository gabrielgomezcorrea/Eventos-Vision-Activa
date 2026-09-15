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

class RecordatorioDeReserva extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $orden) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Tu reserva {$this->orden->number} vence pronto",
        );
    }

    public function content(): Content
    {
        [, $token] = MagicLink::emitir($this->orden->event, $this->orden->responsible_email, $this->orden);

        return new Content(
            markdown: 'mail.recordatorio-de-reserva',
            with: [
                'orden' => $this->orden,
                'event' => $this->orden->event,
                'urlEstado' => route('inscripcion.estado', ['token' => $token]),
            ],
        );
    }
}
