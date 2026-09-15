<?php

namespace App\Enums;

/**
 * Situación financiera de la orden.
 */
enum PaymentStatus: string implements EstadoVisible
{
    case Pendiente = 'pending';
    case EnValidacion = 'in_review';
    case Observado = 'observed';
    case Rechazado = 'rejected';
    case Aprobado = 'approved';

    public function label(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente de pago',
            self::EnValidacion => 'Comprobante en validación',
            self::Observado => 'Pago observado',
            self::Rechazado => 'Pago rechazado',
            self::Aprobado => 'Pago aprobado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pendiente => 'gray',
            self::EnValidacion => 'info',
            self::Observado => 'warning',
            self::Rechazado => 'danger',
            self::Aprobado => 'success',
        };
    }

    /** Solo con el pago aprobado se liberan credenciales. */
    public function liberaCredenciales(): bool
    {
        return $this === self::Aprobado;
    }

    /**
     * Con un comprobante en revisión la reserva no debe vencer mientras
     * Contabilidad no se pronuncie.
     */
    public function congelaElVencimiento(): bool
    {
        return in_array($this, [self::EnValidacion, self::Aprobado], true);
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return $this->color();
    }
}
