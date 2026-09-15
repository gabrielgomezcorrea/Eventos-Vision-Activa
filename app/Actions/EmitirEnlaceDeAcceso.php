<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Mail\EnlaceDeAcceso;
use App\Models\Event;
use App\Models\MagicLink;
use App\Models\Order;
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
    public function __invoke(Event $event, string $email, ?string $ip = null): bool
    {
        $email = mb_strtolower(trim($email));

        if (! $this->permitido($email, $ip)) {
            return false;
        }

        $orden = $this->ordenVigenteDe($event, $email);

        [$link, $token] = MagicLink::emitir($event, $email, $orden);

        Mail::to($email)->queue(new EnlaceDeAcceso(
            event: $event,
            url: route('inscripcion.acceso', ['token' => $token]),
            numeroDeOrden: $orden?->number,
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
