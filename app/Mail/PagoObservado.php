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

class PagoObservado extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Order $orden,
        public string $observacion,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Comprobante observado · inscripción {$this->orden->number}");
    }

    public function content(): Content
    {
        // Se emite un enlace nuevo: sin él, el cliente no tiene por dónde
        // enviar el comprobante corregido que le estamos pidiendo.
        [, $token] = MagicLink::emitir($this->orden->event, $this->orden->responsible_email, $this->orden);

        return new Content(
            markdown: 'mail.pago-observado',
            with: [
                'orden' => $this->orden,
                'event' => $this->orden->event,
                'observacion' => $this->observacion,
                'urlEstado' => route('inscripcion.estado', ['token' => $token]),
            ],
        );
    }
}
