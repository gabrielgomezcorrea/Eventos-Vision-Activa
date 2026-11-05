<?php

namespace App\Actions;

use App\Enums\PaymentStatus;
use App\Exceptions\CodigoNoAplicable;
use App\Models\DiscountCode;
use App\Models\Order;
use App\Models\Participant;
use App\Models\User;
use App\Support\Auditor;
use Illuminate\Support\Facades\DB;

/**
 * El equipo quita el código de una inscripción que aún no está pagada. El
 * total vuelve a calcularse con el descuento por cantidad, si corresponde, y
 * el cupo de la persona se libera si no tiene otra inscripción viva con él.
 */
class QuitarCodigoDesdePanel
{
    /** @throws CodigoNoAplicable */
    public function __invoke(Order $orden, User $usuario): Order
    {
        return DB::transaction(function () use ($orden): Order {
            $fresca = Order::whereKey($orden->getKey())->lockForUpdate()->firstOrFail();

            if ($fresca->discount_code_id === null) {
                throw CodigoNoAplicable::porque('Esta inscripción no tiene código.');
            }

            if ($fresca->payment_status === PaymentStatus::Aprobado) {
                throw CodigoNoAplicable::porque('La inscripción ya está pagada: no se puede quitar el código.');
            }

            $codigo = DiscountCode::find($fresca->discount_code_id);
            $totalAnterior = (int) $fresca->total;

            $fresca->unsetRelation('participantesVigentes');
            $fresca->load('event.discountTiers', 'participantesVigentes');

            if ($fresca->event->cuentaDescuentoPorConjunto() && $fresca->group_id !== null) {
                $fresca->load('group.orders.participantesVigentes');
            }

            $subtotal = (int) $fresca->participantesVigentes->sum(fn (Participant $p): int => (int) $p->unit_price);
            $tramo = $fresca->event->tramoDeDescuento($fresca->participantesParaDescuento());
            $descuento = $tramo?->calcular($subtotal) ?? 0;

            $fresca->forceFill([
                'discount_code_id' => null,
                'discount_code_email' => null,
                'subtotal' => $subtotal,
                'discount_amount' => $descuento,
                'discount_label' => $descuento > 0 ? $tramo->etiqueta() : null,
                'total' => $subtotal - $descuento,
            ])->save();

            $codigo?->recalcularUsos();

            Auditor::registrar(
                sobre: $fresca,
                accion: 'orden.codigo_quitado',
                estadoAnterior: $fresca->payment_status->value,
                estadoNuevo: $fresca->payment_status->value,
                propiedades: [
                    'codigo' => $codigo?->formateado(),
                    'total_anterior' => $totalAnterior,
                    'total_nuevo' => $subtotal - $descuento,
                ],
            );

            return $fresca;
        });
    }
}
