<?php

namespace App\Mail;

use App\Models\MagicLink;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Abono aprobado que todavía no cubre el total: confirma lo recibido y dice el
 * saldo. Las credenciales no salen hasta pagar todo, así que este correo no las
 * anuncia.
 */
class AbonoRecibido extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $orden, public Payment $abono) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Abono recibido · inscripción {$this->orden->number}");
    }

    public function content(): Content
    {
        [, $token] = MagicLink::emitir($this->orden->event, $this->orden->responsible_email, $this->orden);

        return new Content(
            markdown: 'mail.abono-recibido',
            with: [
                'orden' => $this->orden,
                'event' => $this->orden->event,
                'abono' => $this->abono,
                'urlEstado' => route('inscripcion.estado', ['token' => $token]),
            ],
        );
    }
}
