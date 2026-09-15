<?php

namespace App\Http\Controllers\Publico;

use App\Exceptions\EnlaceNoUtilizable;
use App\Http\Controllers\Controller;
use App\Models\MagicLink;
use App\Models\Ticket;
use App\Support\GeneradorQr;
use Illuminate\Contracts\Support\Renderable;

/**
 * Credenciales para el cliente.
 *
 * La credencial individual se resuelve por su propio token; el conjunto de una
 * orden, por el magic link del responsable. En ningún caso se acepta un id.
 */
class TicketController extends Controller
{
    public function __construct(private readonly GeneradorQr $qr) {}

    /** Credencial individual, la que abre el QR. */
    public function mostrar(string $token): Renderable
    {
        $ticket = Ticket::resolver($token);

        abort_if($ticket === null, 404, 'Credencial no encontrada.');

        if (! $ticket->estaVigente()) {
            return view('publico.ticket.revocada', [
                'ticket' => $ticket->load('event'),
            ]);
        }

        return view('publico.ticket.individual', [
            'ticket' => $ticket->load('event', 'order', 'participant.accessType.sessions', 'participant.establishment'),
            'qr' => $this->qr,
        ]);
    }

    /** Todas las credenciales de una orden, para el responsable. */
    public function deLaOrden(string $token): Renderable
    {
        $link = MagicLink::resolver($token);

        if ($link === null || $link->order === null) {
            throw EnlaceNoUtilizable::vencido(MagicLink::porToken($token)?->event);
        }

        $orden = $link->order->load('event');

        return view('publico.ticket.orden', [
            'orden' => $orden,
            'tickets' => $orden->tickets()
                ->vigentes()
                ->with('event', 'order', 'participant.accessType.sessions', 'participant.establishment')
                ->get(),
            'qr' => $this->qr,
        ]);
    }
}
