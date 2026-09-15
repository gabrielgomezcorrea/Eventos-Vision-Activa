<?php

namespace App\Http\Controllers\Acreditacion;

use App\Actions\AcreditarParticipante;
use App\Actions\BuscarParticipantes;
use App\Actions\ResolverCredencial;
use App\Enums\EventStatus;
use App\Enums\Permiso;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Ticket;
use App\Support\Acreditacion\Resultado;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Módulo de acreditación en terreno.
 *
 * Interfaz propia y no Filament: se usa de pie, con un teléfono, apurado y con
 * mala luz. Lo que importa es leer el resultado de un vistazo y confirmar con
 * un botón grande.
 */
class AcreditacionController extends Controller
{
    public function inicio(Request $request): Renderable|RedirectResponse
    {
        $this->autorizar($request);

        $eventos = $this->eventosDisponibles();

        if ($eventos->isEmpty()) {
            return view('acreditacion.sin-eventos');
        }

        $evento = $this->eventoElegido($request, $eventos);

        return view('acreditacion.escaner', [
            'evento' => $evento,
            'eventos' => $eventos,
            'resultado' => null,
            'consulta' => '',
            'coincidencias' => collect(),
        ]);
    }

    /** Resuelve lo escaneado o lo escrito a mano. */
    public function resolver(Request $request, ResolverCredencial $resolver): Renderable|RedirectResponse
    {
        $this->autorizar($request);

        $eventos = $this->eventosDisponibles();
        $evento = $this->eventoElegido($request, $eventos);

        $entrada = (string) $request->input('credencial', '');
        $resultado = $entrada === '' ? null : $resolver($entrada);

        // Una credencial de otro evento no se acredita aquí: sería entregar una
        // pulsera del evento equivocado. Se dice explícitamente cuál es, porque
        // tratarla como inexistente hace pensar que el código está malo.
        if ($resultado?->ticket && $evento && $resultado->ticket->event_id !== $evento->getKey()) {
            $resultado = Resultado::otroEvento($resultado->ticket);
        }

        return view('acreditacion.escaner', [
            'evento' => $evento,
            'eventos' => $eventos,
            'resultado' => $resultado,
            'consulta' => '',
            'coincidencias' => collect(),
        ]);
    }

    public function confirmar(Request $request, AcreditarParticipante $acreditar): Renderable|RedirectResponse
    {
        $this->autorizar($request);

        abort_unless(
            $request->user()->can(Permiso::AcreditarParticipantes->value),
            403,
            'No tienes permiso para acreditar participantes.',
        );

        $ticket = Ticket::query()
            ->with(['event', 'order', 'participant.accessType.sessions', 'participant.establishment', 'accreditation.user'])
            ->findOrFail((int) $request->input('ticket_id'));

        abort_unless($ticket->estaVigente(), 422, 'Esta credencial fue anulada.');
        abort_unless($ticket->order->payment_status->liberaCredenciales(), 422, 'El pago no está confirmado.');

        $acreditar($ticket, $request->user(), (string) $request->input('metodo', 'qr'));

        $eventos = $this->eventosDisponibles();

        return view('acreditacion.escaner', [
            'evento' => $ticket->event,
            'eventos' => $eventos,
            'resultado' => (new ResolverCredencial)($ticket->code),
            'recienAcreditado' => true,
            'consulta' => '',
            'coincidencias' => collect(),
        ]);
    }

    public function buscar(Request $request, BuscarParticipantes $buscar): Renderable|RedirectResponse
    {
        $this->autorizar($request);

        $eventos = $this->eventosDisponibles();
        $evento = $this->eventoElegido($request, $eventos);
        $consulta = trim((string) $request->input('q', ''));

        return view('acreditacion.escaner', [
            'evento' => $evento,
            'eventos' => $eventos,
            'resultado' => null,
            'consulta' => $consulta,
            'coincidencias' => $evento && $consulta !== '' ? $buscar($evento, $consulta) : collect(),
        ]);
    }

    private function autorizar(Request $request): void
    {
        abort_unless(
            $request->user()?->can(Permiso::VerAcreditacion->value),
            403,
            'No tienes acceso al módulo de acreditación.',
        );
    }

    /** @return Collection<int, Event> */
    private function eventosDisponibles(): Collection
    {
        return Event::query()
            ->whereIn('status', [EventStatus::Publicado->value, EventStatus::Cerrado->value])
            ->orderBy('name')
            ->get();
    }

    /** @param  Collection<int, Event>  $eventos */
    private function eventoElegido(Request $request, Collection $eventos): ?Event
    {
        if ($id = $request->input('event_id')) {
            return $eventos->firstWhere('id', (int) $id) ?? $eventos->first();
        }

        return $eventos->first();
    }
}
