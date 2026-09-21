<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Mail\EnlaceDeAcceso;
use App\Models\Event;
use App\Models\MagicLink;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\ProgramRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Emite y envía un enlace de acceso.
 *
 * Si el correo ya tiene una inscripción viva en el evento, el enlace apunta a
 * esa orden en vez de crear una nueva: así el cliente retoma en vez de duplicar.
 */
class EmitirEnlaceDeAcceso
{
    public function __invoke(Event $event, string $email, ?string $ip = null, bool $nuevaInscripcion = false): bool
    {
        $email = mb_strtolower(trim($email));

        if (! $this->permitido($email, $ip)) {
            return false;
        }

        // Con dos o más colegios vigentes en el mismo conjunto, el correo es
        // uno solo con el enlace del conjunto: no uno por colegio.
        if (! $nuevaInscripcion) {
            $colegios = $this->colegiosVigentesDe($event, $email);

            if ($colegios !== null) {
                [, $token] = MagicLink::emitirParaGrupo($event, $email, $colegios->first()->group);

                Mail::to($email)->queue(new EnlaceDeAcceso(
                    event: $event,
                    url: route('inscripcion.acceso', ['token' => $token]),
                    nombre: $this->nombreDe($event, $email, $colegios->first()),
                    colegios: $colegios,
                ));

                return true;
            }
        }

        // Inscribing another school starts its own order, even with one alive.
        $orden = $nuevaInscripcion ? null : $this->ordenVigenteDe($event, $email);

        // Toda orden queda en un conjunto, aunque hoy tenga una sola: así no
        // hay dos caminos en el código para "un colegio" y "varios colegios".
        // El enlace que se manda sigue siendo el de la orden; el enlace propio
        // del conjunto lo emite el recorrido con varios colegios.
        if ($orden !== null && $orden->group_id === null) {
            $orden->forceFill(['group_id' => OrderGroup::paraResponsable($event, $email)->getKey()])->save();
        }

        [$link, $token] = MagicLink::emitir($event, $email, $orden);

        Mail::to($email)->queue(new EnlaceDeAcceso(
            event: $event,
            url: route('inscripcion.acceso', ['token' => $token]),
            numeroDeOrden: $orden?->number,
            nombre: $this->nombreDe($event, $email, $orden),
        ));

        return true;
    }

    /**
     * Orden a la que debe volver este correo: un borrador o una reserva viva.
     * Las canceladas y vencidas no se retoman.
     */
    private function ordenVigenteDe(Event $event, string $email): ?Order
    {
        return Order::query()
            ->where('event_id', $event->getKey())
            ->where('responsible_email', $email)
            ->whereIn('status', [OrderStatus::Borrador->value, OrderStatus::Reservada->value])
            ->latest('id')
            ->first();
    }

    /**
     * Colegios vigentes de un conjunto con dos o más órdenes, o null si el
     * responsable no tiene un conjunto así en este evento (0 o 1 orden se
     * resuelven como hoy, orden por orden).
     *
     * @return Collection<int, Order>|null
     */
    private function colegiosVigentesDe(Event $event, string $email): ?Collection
    {
        $vigentes = Order::query()
            ->where('event_id', $event->getKey())
            ->where('responsible_email', $email)
            ->whereNotNull('group_id')
            ->whereIn('status', [OrderStatus::Borrador->value, OrderStatus::Reservada->value])
            ->with('establishments', 'group')
            ->get()
            ->groupBy('group_id')
            ->sortKeysDesc()
            ->first();

        return $vigentes !== null && $vigentes->count() > 1 ? $vigentes : null;
    }

    /**
     * Name to greet with: the responsible of the order being resumed or, for a
     * first enrollment, whoever asked for the program with that email.
     */
    private function nombreDe(Event $event, string $email, ?Order $orden): ?string
    {
        return $orden?->responsible_name
            ?: ProgramRequest::query()
                ->where('event_id', $event->getKey())
                ->where('email', $email)
                ->latest('id')
                ->value('first_name');
    }

    /**
     * Límite por correo y por IP. Evita que el formulario público se use para
     * enviar correo no deseado a direcciones de terceros.
     */
    private function permitido(string $email, ?string $ip): bool
    {
        $max = config('magic_links.max_per_window');
        $ventana = config('magic_links.window_minutes') * 60;

        $claves = array_filter([
            'magic-link:email:'.sha1($email),
            $ip ? 'magic-link:ip:'.sha1($ip) : null,
        ]);

        foreach ($claves as $clave) {
            if (RateLimiter::tooManyAttempts($clave, $max)) {
                return false;
            }
        }

        foreach ($claves as $clave) {
            RateLimiter::hit($clave, $ventana);
        }

        return true;
    }
}
