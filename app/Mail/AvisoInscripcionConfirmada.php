<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/** Aviso interno: un cliente confirmó una inscripción (un colegio o varios). No va al cliente. */
class AvisoInscripcionConfirmada extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param  Collection<int, Order>  $ordenes */
    public function __construct(public Collection $ordenes) {}

    public function envelope(): Envelope
    {
        $primera = $this->ordenes->first();
        $evento = $primera->event->name;

        return new Envelope(subject: $this->ordenes->count() === 1
            ? 'Nueva inscripción: '.($primera->nombreDelColegio() ?? $primera->responsableNombreCompleto())." · {$evento}"
            : "Nueva inscripción: {$this->ordenes->count()} colegios · {$evento}");
    }

    public function content(): Content
    {
        $primera = $this->ordenes->first();

        return new Content(
            markdown: 'mail.aviso-inscripcion-confirmada',
            with: [
                'ordenes' => $this->ordenes,
                'orden' => $primera,
                'url' => $this->ordenes->count() === 1
                    ? route('inscripciones.show', $primera)
                    : route('inscripciones.index', ['buscar' => $primera->responsible_email]),
            ],
        );
    }
}
