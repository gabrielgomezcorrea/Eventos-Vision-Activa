<?php

namespace App\Actions;

use App\Enums\ParticipantStatus;
use App\Exceptions\TicketsNoLiberables;
use App\Models\Order;
use App\Models\Participant;
use App\Models\Ticket;
use App\Support\Auditor;
use App\Support\EnvioDeCredenciales;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Emite las credenciales de una orden.
 *
 * Solo con el pago aprobado. Es idempotente: volver a ejecutarla no duplica
 * tickets ni reemplaza los ya emitidos, porque los eventos de aprobación
 * pueden reintentarse.
 */
class EmitirTickets
{
    /**
     * @return Collection<int, Ticket>
     *
     * @throws TicketsNoLiberables
     */
    public function __invoke(Order $orden): Collection
    {
        if (! $orden->payment_status->liberaCredenciales()) {
            throw TicketsNoLiberables::porque(
                'Las credenciales se liberan solo cuando el pago está aprobado. Estado actual: '
                .$orden->payment_status->label().'.'
            );
        }

        $orden->load('participants.accessType');

        $emitidos = DB::transaction(function () use ($orden): Collection {
            $nuevos = collect();

            foreach ($orden->participantesVigentes as $participante) {
                // Sin tipo de acceso (queda en "-") no hay credencial: no sabría
                // a qué jornadas entra. Sale cuando se le elija uno.
                if ($participante->access_type_id === null || $this->yaTieneTicket($participante)) {
                    continue;
                }

                $nuevos->push($this->emitirPara($orden, $participante));
            }

            return $nuevos;
        });

        if ($emitidos->isNotEmpty()) {
            Auditor::registrar(
                sobre: $orden,
                accion: 'tickets.emitidos',
                propiedades: ['cantidad' => $emitidos->count()],
                actorLabel: 'Sistema',
            );
        }

        // Cada credencial nueva le llega a su participante (09/11/2026; antes
        // solo al invitado). Al responsable lo avisa quien deja la orden pagada.
        EnvioDeCredenciales::aParticipantes($emitidos);

        return $orden->fresh()->load('participants.tickets')->participantesVigentes
            ->flatMap(fn (Participant $p) => $p->tickets->where('revoked_at', null));
    }

    private function yaTieneTicket(Participant $participante): bool
    {
        return $participante->tickets()->vigentes()->exists();
    }

    private function emitirPara(Order $orden, Participant $participante): Ticket
    {
        $token = Ticket::generarToken();

        // El código de respaldo es corto por diseño, así que puede chocar.
        // El índice único es la garantía; aquí solo se reintenta.
        for ($intento = 0; $intento < 5; $intento++) {
            try {
                $ticket = Ticket::create([
                    'event_id' => $orden->event_id,
                    'order_id' => $orden->getKey(),
                    'participant_id' => $participante->getKey(),
                    'code' => Ticket::generarCodigo(),
                    'token' => $token,
                    'token_hash' => Ticket::hash($token),
                    'issued_at' => now(),
                ]);

                $participante->forceFill(['status' => ParticipantStatus::CredencialEmitida])->save();

                return $ticket;
            } catch (QueryException $e) {
                if ($intento === 4) {
                    throw $e;
                }
            }
        }

        throw TicketsNoLiberables::porque('No fue posible generar un código único para la credencial.');
    }
}
