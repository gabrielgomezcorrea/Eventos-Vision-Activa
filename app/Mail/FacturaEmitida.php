<?php

namespace App\Mail;

use App\Models\InvoiceRecord;
use App\Models\MagicLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Envía al cliente el documento tributario ya emitido.
 *
 * Va con el archivo adjunto y además con el enlace a la inscripción: el adjunto
 * es lo que administración espera reenviar, y el enlace sirve cuando el correo
 * se reenvía y el archivo se pierde por el camino.
 */
class FacturaEmitida extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public InvoiceRecord $factura) {}

    public function envelope(): Envelope
    {
        $orden = $this->factura->order;

        return new Envelope(
            subject: $this->factura->descripcion()." · inscripción {$orden->number}",
        );
    }

    public function content(): Content
    {
        $orden = $this->factura->order;

        [, $token] = MagicLink::emitir($orden->event, $orden->responsible_email, $orden);

        return new Content(
            markdown: 'mail.factura-emitida',
            with: [
                'factura' => $this->factura,
                'orden' => $orden,
                'event' => $orden->event,
                'urlEstado' => route('inscripcion.estado', ['token' => $token]),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if (! $this->factura->tieneArchivo()) {
            return [];
        }

        return [
            Attachment::fromStorageDisk($this->factura->disk, $this->factura->path)
                ->as($this->factura->original_name ?: $this->factura->descripcion().'.pdf'),
        ];
    }
}
