<?php

namespace App\Actions;

use App\Enums\OrderKind;
use App\Mail\EnlaceDeAcceso;
use App\Models\Event;
use App\Models\Order;
use App\Models\ProgramRequest;
use App\Models\User;
use App\Support\Auditor;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * El equipo inicia una inscripción con solo el correo de quien hace el trámite
 * (el colegio mandó su ficha por correo). El cliente recibe su enlace y todos
 * los correos normales; los datos se llenan en el mismo portal.
 *
 * Con `$avisarAlCliente` apagado la inscripción queda sin correos automáticos
 * al cliente (`orders.notify_client`); el equipo puede usar "Reenviar al cliente".
 *
 * Si ese correo ya tiene una inscripción viva en el evento no se crea otra:
 * se devuelve la existente. Usa el mismo candado que la inscripción directa,
 * así un clic del cliente y el equipo creando a la vez no duplican nada.
 */
class CrearInscripcionDesdePanel
{
    /**
     * @return array{0: Order, 1: bool} la orden y si es nueva
     *
     * @throws LockTimeoutException
     */
    public function __invoke(Event $event, string $email, User $user, EmitirEnlaceDeAcceso $emitir, bool $avisarAlCliente = true): array
    {
        return Cache::lock('inscripcion-directa:'.$event->getKey().':'.sha1($email), 10)
            ->block(5, fn (): array => DB::transaction(function () use ($event, $email, $user, $emitir, $avisarAlCliente): array {
                $colegios = $emitir->colegiosVigentesDe($event, $email);
                $existente = $colegios?->first() ?? $emitir->ordenVigenteDe($event, $email);

                if ($existente !== null) {
                    return [$existente, false];
                }

                $solicitud = ProgramRequest::query()
                    ->where('event_id', $event->getKey())
                    ->where('email', $email)
                    ->latest('id')
                    ->first()
                    ?? ProgramRequest::create([
                        'event_id' => $event->getKey(),
                        'email' => $email,
                        'origin' => ProgramRequest::ORIGEN_DIRECTO,
                        'consented_at' => now(),
                    ]);

                // Aquí sí se sabe quién hace el trámite: el correo nace en el
                // bloque Responsable, que el cliente completa en el portal.
                $orden = Order::create([
                    'event_id' => $event->getKey(),
                    'program_request_id' => $solicitud->getKey(),
                    'created_by_user_id' => $user->getKey(),
                    'notify_client' => $avisarAlCliente,
                    'kind' => OrderKind::Institucional,
                    'responsible_email' => $email,
                    'responsible_name' => '',
                ]);

                // Sin aviso, el enlace igual existe (el equipo lo abre desde la
                // ficha) pero al cliente no le llega ningún correo automático.
                $url = $emitir->urlDeAcceso($solicitud);

                if ($avisarAlCliente) {
                    Mail::to($email)->queue(new EnlaceDeAcceso(event: $event, url: $url));
                }

                Auditor::registrar($orden, 'orden.creada_desde_panel', propiedades: ['correo' => $email]);

                return [$orden, true];
            }));
    }
}
