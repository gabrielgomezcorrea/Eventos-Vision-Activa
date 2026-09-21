<?php

namespace App\Exceptions;

use App\Enums\OrderStatus;
use App\Models\Order;
use RuntimeException;

class ComprobanteNoAceptado extends RuntimeException
{
    public static function para(Order $orden): self
    {
        return new self(match (true) {
            $orden->status === OrderStatus::Reservada && $orden->saldo() === 0 => 'El pago de esta inscripción ya fue aprobado.',
            $orden->tieneAbonoEnRevision() => 'Ya hay un comprobante en validación para esta inscripción. Te avisamos cuando Contabilidad lo revise.',
            $orden->status === OrderStatus::Borrador => 'Confirma la inscripción antes de informar el pago.',
            $orden->status === OrderStatus::Vencida => 'La reserva de esta inscripción venció. Contáctanos para revisar la disponibilidad.',
            $orden->status === OrderStatus::Cancelada => 'Esta inscripción fue cancelada.',
            default => 'Esta inscripción no admite comprobantes en este momento.',
        });
    }
}
