<?php

namespace App\Actions;

use App\Exceptions\CuposInsuficientes;
use App\Exceptions\EnlaceNoUtilizable;
use App\Models\Invitation;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * La persona invitada completa sus datos: se consume la invitación y se crea su
 * inscripción sin pago, todo en una transacción. Si no hay cupo, la invitación
 * no se consume y el mismo enlace sirve cuando se amplíe la jornada.
 */
class AceptarInvitacion
{
    public function __construct(private readonly RegistrarInvitado $registrar) {}

    /**
     * @param  array<string, mixed>  $datos  ya validados y normalizados; el correo y el acceso los pone la invitación
     *
     * @throws EnlaceNoUtilizable
     * @throws CuposInsuficientes
     */
    public function __invoke(Invitation $invitacion, array $datos): Order
    {
        return DB::transaction(function () use ($invitacion, $datos): Order {
            // Un solo uso: la condición viaja en el UPDATE, así dos envíos
            // simultáneos no crean dos inscripciones.
            $filas = Invitation::query()
                ->whereKey($invitacion->getKey())
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>=', now())
                ->update(['used_at' => now()]);

            if ($filas === 0) {
                throw EnlaceNoUtilizable::invitacionNoDisponible($invitacion->event);
            }

            $orden = ($this->registrar)($invitacion->event, [
                ...$datos,
                'email' => $invitacion->email,
                'access_type_id' => $invitacion->access_type_id,
            ]);

            $invitacion->forceFill(['order_id' => $orden->getKey()])->save();

            return $orden;
        });
    }
}
