<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Mail\EnlaceDeAcceso;
use App\Models\Event;
use App\Models\MagicLink;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\ProgramRequest;
use Illuminate\Database\Eloquent\Builder;
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

        // Si ese correo pidió el programa, el enlace es siempre el mismo de la
        // solicitud: quien cambió de responsable no abre un segundo borrador.
        $solicitud = $nuevaInscripcion ? null : $this->solicitudDe($event, $email);

        // Con dos o más colegios vigentes en el mismo conjunto, el correo es
        // uno solo con el enlace del conjunto: no uno por colegio.
        if (! $nuevaInscripcion) {
            $colegios = $this->colegiosVigentesDe($event, $email);

            if ($colegios !== null) {
                $url = $solicitud
                    ? $this->urlDeAcceso($solicitud)
                    : route('inscripcion.acceso', ['token' => MagicLink::emitirParaGrupo($event, $email, $colegios->first()->group)[1]]);

                Mail::to($email)->queue(new EnlaceDeAcceso(
                    event: $event,
                    url: $url,
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

        $url = $solicitud
            ? $this->urlDeAcceso($solicitud)
            : route('inscripcion.acceso', ['token' => MagicLink::emitir($event, $email, $orden)[1]]);

        Mail::to($email)->queue(new EnlaceDeAcceso(
            event: $event,
            url: $url,
            numeroDeOrden: $orden?->number,
            nombre: $this->nombreDe($event, $email, $orden),
        ));

        return true;
    }

    /**
     * URL de acceso de una solicitud: siempre la misma para ese correo en ese
     * evento, así el directivo puede reenviarla o pasarla sin duplicar
     * borradores ni cortar el enlace de nadie. No manda correo ni suma el
     * límite de `__invoke`: va dentro del correo del programa, que ya pasó por
     * el honeypot, el tiempo mínimo y el tope por correo del formulario.
     *
     * Reutiliza el enlace guardado; si no hay uno vigente (o su inscripción
     * quedó cancelada o vencida) emite otro sin revocar el anterior. Si el
     * correo ya tiene inscripción viva retoma esa; si no, el enlace queda sin
     * orden y el borrador nace al hacer clic (`InscripcionController::acceso`).
     */
    public function urlDeAcceso(ProgramRequest $solicitud): string
    {
        $event = $solicitud->event;
        $email = mb_strtolower(trim($solicitud->email));

        $guardado = $this->enlaceGuardadoDe($event, $email);

        if ($guardado !== null) {
            return route('inscripcion.acceso', ['token' => $guardado->token_encrypted]);
        }

        $colegios = $this->colegiosVigentesDe($event, $email);

        if ($colegios !== null) {
            [, $token] = MagicLink::emitirParaGrupo($event, $email, $colegios->first()->group, $solicitud);
        } else {
            $orden = $this->ordenVigenteDe($event, $email);

            if ($orden !== null && $orden->group_id === null) {
                $orden->forceFill(['group_id' => OrderGroup::paraResponsable($event, $email)->getKey()])->save();
            }

            [, $token] = MagicLink::emitir($event, $email, $orden, $solicitud);
        }

        return route('inscripcion.acceso', ['token' => $token]);
    }

    /** Solicitud más reciente de ese correo en el evento. */
    private function solicitudDe(Event $event, string $email): ?ProgramRequest
    {
        return ProgramRequest::query()
            ->where('event_id', $event->getKey())
            ->where('email', $email)
            ->latest('id')
            ->first();
    }

    /**
     * Enlace de programa ya emitido para este correo que todavía sirve: vigente
     * y sin inscripción, o con alguna inscripción que no esté cancelada ni vencida.
     */
    public function enlaceGuardadoDe(Event $event, string $email): ?MagicLink
    {
        $muertas = [OrderStatus::Cancelada->value, OrderStatus::Vencida->value];

        // Con una inscripción viva (un colegio o un conjunto) el enlace debe
        // llevar a esa; uno que apunta a otra orden no sirve de atajo.
        $colegios = $this->colegiosVigentesDe($event, $email);
        $viva = $colegios === null ? $this->ordenVigenteDe($event, $email) : null;

        return MagicLink::query()
            ->where('event_id', $event->getKey())
            ->where('email', $email)
            ->whereNotNull('program_request_id')
            ->whereNotNull('token_encrypted')
            ->whereNull('revoked_at')
            ->with('event.sessions')
            ->latest('id')
            ->get()
            ->first(function (MagicLink $link) use ($muertas, $colegios, $viva): bool {
                if (! $link->estaVigente()) {
                    return false;
                }

                if ($colegios !== null) {
                    return $link->order_group_id !== null && $link->order_group_id === $colegios->first()->group_id;
                }

                if ($viva !== null) {
                    return $link->order_id === $viva->getKey()
                        || ($link->order_group_id !== null && $link->order_group_id === $viva->group_id);
                }

                $estados = match (true) {
                    $link->order_id !== null => Order::query()->whereKey($link->order_id)->pluck('status'),
                    $link->order_group_id !== null => Order::query()->where('group_id', $link->order_group_id)->pluck('status'),
                    default => collect(),
                };

                return $estados->isEmpty()
                    || $estados->contains(fn ($estado) => ! in_array($estado instanceof OrderStatus ? $estado->value : $estado, $muertas, true));
            });
    }

    /**
     * Orden a la que debe volver este correo: un borrador o una reserva viva.
     * Las canceladas y vencidas no se retoman.
     */
    public function ordenVigenteDe(Event $event, string $email): ?Order
    {
        return Order::query()
            ->where('event_id', $event->getKey())
            // Un borrador con el responsable aún vacío es de quien tiene su enlace.
            ->where(fn ($q) => $q
                ->where('responsible_email', $email)
                ->orWhere(fn ($vacio) => $vacio->where('responsible_email', '')->whereHas('magicLinks', fn ($l) => $l->where('email', $email))))
            ->tap(fn ($consulta) => self::conEnlaceDe($consulta, $email))
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
    public function colegiosVigentesDe(Event $event, string $email): ?Collection
    {
        $vigentes = Order::query()
            ->where('event_id', $event->getKey())
            ->where('responsible_email', $email)
            ->tap(fn ($consulta) => self::conEnlaceDe($consulta, $email))
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
     * Deja fuera las inscripciones cuyos enlaces son todos de otro correo: su
     * responsable escribió este correo a mano, y sin esto quien pide su enlace
     * recibiría el de una inscripción ajena. Las que nunca tuvieron enlace
     * (las crea el panel) se retoman como siempre.
     *
     * @param  Builder<Order>  $consulta
     */
    private static function conEnlaceDe(Builder $consulta, string $email): void
    {
        $consulta->where(fn ($q) => $q
            ->whereHas('magicLinks', fn ($l) => $l->where('email', $email))
            ->orWhereHas('group.magicLinks', fn ($l) => $l->where('email', $email))
            ->orWhere(fn ($sinEnlaces) => $sinEnlaces
                ->whereDoesntHave('magicLinks')
                ->whereDoesntHave('group.magicLinks')));
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
