<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\CancelacionNoPermitida;
use App\Models\Order;
use App\Support\Auditor;
use Illuminate\Support\Facades\DB;

/**
 * Cancela una reserva a pedido del cliente y devuelve sus cupos.
 *
 * No se cancela con plata de por medio: con un comprobante en validación o el
 * pago aprobado habría que decidir una devolución, y esa política no está
 * definida. Contabilidad resuelve el pago primero.
 */
class CancelarOrden
{
    public function __construct(private ReservarCupos $cupos) {}

    /** Por qué no se puede cancelar, o null si se puede. */
    public static function impedimento(Order $orden): ?string
    {
        return match (true) {
            $orden->status !== OrderStatus::Reservada => 'Solo se cancela una inscripción con su reserva vigente.',
            $orden->payment_status === PaymentStatus::Aprobado => 'El pago ya está aprobado y las credenciales emitidas.',
            $orden->payment_status === PaymentStatus::EnValidacion => 'Tiene un comprobante en validación: Contabilidad debe revisarlo primero.',
            default => null,
        };
    }

    public function __invoke(Order $orden, string $motivo): Order
    {
        return DB::transaction(function () use ($orden, $motivo): Order {
            $fresca = Order::whereKey($orden->getKey())->lockForUpdate()->firstOrFail();

            if (($impedimento = self::impedimento($fresca)) !== null) {
                throw CancelacionNoPermitida::porque($impedimento);
            }

            $fresca->load('participantesVigentes.accessType.sessions');
            $this->cupos->devolver($fresca->consumoDeCupos());
            $fresca->discountCode?->devolverUsos($fresca->participantesVigentes->count());

            $fresca->forceFill(['status' => OrderStatus::Cancelada, 'cancelled_at' => now()])->save();

            Auditor::registrar(
                sobre: $fresca,
                accion: 'orden.cancelada',
                estadoAnterior: OrderStatus::Reservada->value,
                estadoNuevo: OrderStatus::Cancelada->value,
                comentario: $motivo,
            );

            return $fresca;
        });
    }
}
