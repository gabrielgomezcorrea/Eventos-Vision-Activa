<?php

namespace App\Actions;

use App\Enums\ParticipantStatus;
use App\Models\Accreditation;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Auditor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Registra la acreditación de un participante y la entrega de su pulsera.
 *
 * Es idempotente por diseño: si dos operadores confirman el mismo ticket a la
 * vez, el índice único de la base rechaza el segundo insert y aquí se devuelve
 * la acreditación que ya existía, en vez de fallar. En la puerta un error
 * detiene la fila; un mensaje de "ya estaba acreditado" no.
 */
class AcreditarParticipante
{
    public function __invoke(Ticket $ticket, ?User $usuario = null, string $metodo = 'qr'): Accreditation
    {
        $acceso = $ticket->participant->accessType;

        try {
            $acreditacion = DB::transaction(function () use ($ticket, $usuario, $metodo, $acceso): Accreditation {
                $acreditacion = Accreditation::create([
                    'ticket_id' => $ticket->getKey(),
                    'event_id' => $ticket->event_id,
                    'participant_id' => $ticket->participant_id,
                    'user_id' => $usuario?->getKey(),
                    'user_label' => $usuario?->name,
                    // Copia del color al momento de entregar: si después se
                    // cambia el acceso, el registro debe seguir siendo fiel.
                    'wristband_label' => $acceso?->wristband_label,
                    'wristband_color' => $acceso?->wristband_color,
                    'method' => $metodo,
                    'accredited_at' => now(),
                ]);

                $ticket->participant->forceFill([
                    'status' => ParticipantStatus::Acreditado,
                ])->save();

                return $acreditacion;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Otro operador ganó la carrera. No es un error operativo.
            return $ticket->accreditation()->firstOrFail();
        }

        Auditor::registrar(
            sobre: $ticket->order,
            accion: 'participante.acreditado',
            estadoNuevo: ParticipantStatus::Acreditado->value,
            propiedades: [
                'participante' => $ticket->participant->nombre_completo,
                'ticket' => $ticket->code,
                'metodo' => $metodo,
            ],
        );

        return $acreditacion;
    }
}
