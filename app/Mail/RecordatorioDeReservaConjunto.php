<?php

namespace App\Mail;

use App\Models\MagicLink;
use App\Models\Order;
use App\Models\OrderGroup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Recordatorio de vencimiento para las órdenes de un mismo conjunto que
 * vencen el mismo día. Las que vencen otro día van en su propio aviso.
 */
class RecordatorioDeReservaConjunto extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param  Collection<int, Order>  $ordenes */
    public function __construct(public OrderGroup $grupo, public Collection $ordenes) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Tu reserva en {$this->ordenes->count()} establecimientos vence pronto",
        );
    }

    public function content(): Content
    {
        $responsable = $this->ordenes->first();

        [, $token] = MagicLink::emitirParaGrupo($this->grupo->event, $responsable->responsible_email, $this->grupo);

        return new Content(
            markdown: 'mail.recordatorio-de-reserva-conjunto',
            with: [
                'ordenes' => $this->ordenes,
                'event' => $this->grupo->event,
                'total' => (int) $this->ordenes->sum('total'),
                'urlEstado' => route('inscripcion.estado', ['token' => $token]),
            ],
        );
    }
}
