<?php

namespace App\Support;

use App\Enums\OrderKind;
use App\Mail\CredencialParticipante;
use App\Mail\PagoAprobado;
use App\Models\Order;
use App\Models\Ticket;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends credentials when an order is fully paid (Gabriel, 09/11/2026): the
 * responsible person gets the "payment approved" email with all of them and
 * each participant with an email gets their own. It ignores the "send email
 * to the client" checkbox: that one silences reminders, never the entry to
 * the event. Records where each one went so the panel can show it.
 * Queuing failures are logged and never break the payment or the issuance.
 */
class EnvioDeCredenciales
{
    public static function alResponsable(Order $orden): void
    {
        $correo = mb_strtolower(trim((string) $orden->responsible_email));

        if ($orden->kind === OrderKind::Invitado || ! filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        try {
            Mail::to($correo)->queue(new PagoAprobado($orden));
            $orden->forceFill(['credentials_sent_to' => $correo, 'credentials_sent_at' => now()])->save();
        } catch (\Throwable $e) {
            Log::warning('No se pudo encolar el correo de credenciales al responsable.', [
                'orden' => $orden->number,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @param  Collection<int, Ticket>  $tickets */
    public static function aParticipantes(Collection $tickets): void
    {
        foreach ($tickets as $ticket) {
            $correo = mb_strtolower(trim((string) $ticket->participant?->email));

            if (! filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            try {
                Mail::to($correo)->queue(new CredencialParticipante($ticket));
                $ticket->forceFill(['emailed_at' => now(), 'emailed_to' => $correo])->save();
            } catch (\Throwable $e) {
                Log::warning('No se pudo encolar la credencial del participante.', [
                    'ticket_id' => $ticket->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
