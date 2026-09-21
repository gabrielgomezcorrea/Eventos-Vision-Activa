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
 * Confirmación de un conjunto de dos o más colegios, con las instrucciones de
 * pago de cada uno y un solo enlace para verlas y subir cada comprobante.
 *
 * Los correos de un abono concreto (comprobante recibido, pago aprobado,
 * factura emitida...) siguen siendo uno por colegio: este es solo el aviso de
 * que el conjunto quedó reservado.
 */
class ConjuntoConfirmado extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param  Collection<int, Order>  $ordenes */
    public function __construct(public OrderGroup $grupo, public Collection $ordenes) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Inscripción confirmada en {$this->ordenes->count()} establecimientos · instrucciones de pago",
        );
    }

    public function content(): Content
    {
        $responsable = $this->ordenes->first();

        [, $token] = MagicLink::emitirParaGrupo($this->grupo->event, $responsable->responsible_email, $this->grupo);

        return new Content(
            markdown: 'mail.conjunto-confirmado',
            with: [
                'grupo' => $this->grupo,
                'ordenes' => $this->ordenes,
                'responsable' => $responsable,
                'event' => $this->grupo->event,
                'total' => (int) $this->ordenes->sum('total'),
                'urlSeguimiento' => route('inscripcion.estado', ['token' => $token]),
            ],
        );
    }
}
