<?php

namespace App\Actions;

use App\Enums\ParticipantStatus;
use App\Exceptions\TicketsNoLiberables;
use App\Mail\CredencialParticipante;
use App\Models\Order;
use App\Models\Participant;
use App\Models\Ticket;
use App\Support\Auditor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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
                if ($this->yaTieneTicket($participante)) {
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

        $this->enviarACadaParticipante($emitidos);

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

    /**
     * Envío individual best-effort: en inscripciones institucionales muchos
     * participantes vienen sin correo o con uno mal escrito, y eso no puede
     * hacer fallar la emisión ni la orden.
     *
     * @param  Collection<int, Ticket>  $tickets
     */
    private function enviarACadaParticipante(Collection $tickets): void
    {
        foreach ($tickets as $ticket) {
            $correo = $ticket->participant->email;

            if (blank($correo) || ! filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            try {
                Mail::to($correo)->queue(new CredencialParticipante($ticket));
                $ticket->forceFill(['emailed_at' => now()])->save();
            } catch (\Throwable $e) {
                Log::warning('No se pudo encolar la credencial del participante.', [
                    'ticket_id' => $ticket->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
