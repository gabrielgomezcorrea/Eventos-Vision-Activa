<?php

namespace App\Actions;

use App\Models\Order;
use App\Support\Auditor;
use Illuminate\Support\Facades\DB;

/**
 * Pasa la inscripción a otra persona: cambia el correo del responsable en la
 * orden y en todo su conjunto, para que los correos de pago y credenciales
 * lleguen a quien hace el trámite.
 *
 * No toca los enlaces ni la solicitud (siguen sirviendo), ni el código de
 * descuento (su persona es `discount_code_email`), ni el correo del conjunto:
 * el conjunto se busca por el correo del enlace, y cambiarlo aquí metería
 * esta inscripción en el conjunto de quien tenga el correo nuevo.
 *
 * El cliente lo cambia solo en borrador; después lo corrige el panel.
 */
class CambiarCorreoDelResponsable
{
    public function __invoke(Order $orden, string $correo, ?string $actor = null): void
    {
        $correo = mb_strtolower(trim($correo));

        if ($correo === mb_strtolower(trim($orden->responsible_email))) {
            return;
        }

        DB::transaction(function () use ($orden, $correo, $actor): void {
            $ordenes = $orden->group_id !== null
                ? Order::query()->where('group_id', $orden->group_id)->get()
                : collect();

            if (! $ordenes->contains('id', $orden->getKey())) {
                $ordenes->push($orden);
            }

            foreach ($ordenes as $hermana) {
                $antes = $hermana->responsible_email;

                if ($antes === $correo) {
                    continue;
                }

                $hermana->forceFill(['responsible_email' => $correo])->save();

                Auditor::registrar(
                    sobre: $hermana,
                    accion: 'orden.correo_responsable_cambiado',
                    propiedades: ['antes' => $antes, 'despues' => $correo],
                    actorLabel: $actor,
                );
            }
        });

        // La instancia que llamó queda al día sin recargarla.
        $orden->refresh();
    }
}
