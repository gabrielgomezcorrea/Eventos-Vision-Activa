<?php

namespace App\Actions;

use App\Enums\ParticipantStatus;
use App\Exceptions\ReemplazoNoPermitido;
use App\Mail\CredencialParticipante;
use App\Models\Participant;
use App\Models\User;
use App\Support\Auditor;
use App\Support\Rut;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Reemplaza a un participante conservando su tipo de acceso.
 *
 * El MVP solo admite el reemplazo a igual valor: cambiar a un acceso más caro
 * generaría un saldo pendiente y eso está fuera de alcance. El saliente no se
 * borra, queda como historial, y su credencial se anula para que no sirva en
 * la puerta.
 */
class ReemplazarParticipante
{
    /**
     * @param  array<string, mixed>  $datos  Datos ya validados por su Form Request.
     */
    public function __invoke(
        Participant $saliente,
        array $datos,
        ?User $usuario = null,
        ?string $motivo = null,
    ): Participant {
        $this->verificar($saliente);

        $entrante = DB::transaction(function () use ($saliente, $datos, $motivo): Participant {
            $entrante = $saliente->order->participants()->create([
                'first_name' => $datos['first_name'],
                'last_name' => $datos['last_name'] ?? null,
                'rut' => Rut::normalizar($datos['rut'] ?? null),
                'position' => $datos['position'] ?? null,
                'email' => $datos['email'] ?? null,
                'phone' => $datos['phone'] ?? null,
                // Mismo acceso y mismo precio congelado: la orden no cambia de
                // monto, así que no hay nada que cobrar ni devolver.
                'access_type_id' => $saliente->access_type_id,
                'unit_price' => $saliente->unit_price,
                'establishment_id' => $datos['establishment_id'] ?? $saliente->establishment_id,
                'status' => ParticipantStatus::Registrado,
            ]);

            // La credencial anterior deja de servir en la puerta.
            foreach ($saliente->tickets()->vigentes()->get() as $ticket) {
                $ticket->revocar($motivo ?: 'Reemplazo de participante');
            }

            $saliente->forceFill([
                'status' => ParticipantStatus::Reemplazado,
                'replaced_by_id' => $entrante->getKey(),
                'replaced_at' => now(),
            ])->save();

            return $entrante;
        });

        Auditor::registrar(
            sobre: $saliente->order,
            accion: 'participante.reemplazado',
            estadoAnterior: ParticipantStatus::Registrado->value,
            estadoNuevo: ParticipantStatus::Reemplazado->value,
            comentario: $motivo,
            propiedades: [
                'sale' => $saliente->nombre_completo,
                'entra' => $entrante->nombre_completo,
                'acceso' => $saliente->accessType?->name,
            ],
            actorLabel: $usuario?->name,
        );

        // Si la orden ya tenía el pago aprobado, el entrante necesita su
        // credencial de inmediato: el evento puede ser mañana.
        if ($saliente->order->payment_status->liberaCredenciales()) {
            app(EmitirTickets::class)($saliente->order->fresh());

            $nuevo = $entrante->fresh()->ticketVigente();

            if ($nuevo && filled($entrante->email) && filter_var($entrante->email, FILTER_VALIDATE_EMAIL)) {
                Mail::to($entrante->email)->queue(new CredencialParticipante($nuevo));
            }
        }

        return $entrante->fresh();
    }

    /** @throws ReemplazoNoPermitido */
    private function verificar(Participant $saliente): void
    {
        if ($saliente->status === ParticipantStatus::Reemplazado) {
            throw ReemplazoNoPermitido::porque('Este participante ya fue reemplazado.');
        }

        if ($saliente->accreditation()->exists()) {
            throw ReemplazoNoPermitido::porque(
                'Este participante ya fue acreditado y retiró su pulsera. El cambio requiere autorización de un administrador.'
            );
        }

        $limite = $saliente->order->event->replacement_deadline;

        if ($limite && $limite->endOfDay()->isPast()) {
            throw ReemplazoNoPermitido::porque(
                'El plazo para reemplazar participantes venció el '.$limite->format('d-m-Y').'.'
            );
        }
    }
}
