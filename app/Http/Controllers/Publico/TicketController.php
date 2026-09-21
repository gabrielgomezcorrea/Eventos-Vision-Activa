<?php

namespace App\Http\Controllers\Publico;

use App\Exceptions\EnlaceNoUtilizable;
use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Models\MagicLink;
use App\Models\Order;
use App\Models\Ticket;
use App\Support\GeneradorQr;
use Illuminate\Contracts\Support\Renderable;

/**
 * Credenciales para el cliente.
 *
 * La credencial individual se resuelve por su propio token; el conjunto de una
 * orden, por el magic link del responsable. Con varios colegios, el colegio
 * de la URL solo elige entre las órdenes ya autorizadas por ese enlace: nunca
 * se acepta un id de orden.
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
    public function deLaOrden(string $token, ?Establishment $establishment = null): Renderable
    {
        $link = MagicLink::resolver($token);

        if ($link === null || ($link->order === null && $link->order_group_id === null)) {
            throw EnlaceNoUtilizable::vencido(MagicLink::porToken($token)?->event);
        }

        $delEnlace = $link->order ?? $link->group->orders()->oldest('id')->firstOrFail();
        $orden = $this->ordenDelConjunto($delEnlace, $establishment)->load('event');

        return view('publico.ticket.orden', [
            'orden' => $orden,
            'tickets' => $orden->tickets()
                ->vigentes()
                ->with('event', 'order', 'participant.accessType.sessions', 'participant.establishment')
                ->get(),
            'qr' => $this->qr,
        ]);
    }

    /**
     * Orden objetivo dentro del conjunto: la del enlace si no se indica
     * colegio, o la del colegio indicado si pertenece al mismo conjunto que
     * el enlace. Nunca se acepta un id de orden desde la URL.
     */
    private function ordenDelConjunto(Order $delEnlace, ?Establishment $establishment): Order
    {
        if ($establishment === null) {
            return $delEnlace;
        }

        $orden = $delEnlace->group?->load('orders.establishments')->orders
            ->first(fn (Order $o) => $o->establishments->contains($establishment));

        abort_if($orden === null, 403);

        return $orden;
    }
}
