<?php

namespace App\Enums;

/**
 * Ciclo de vida de la orden. No mezclar con la situación financiera:
 * un pago observado no cambia este estado.
 */
enum OrderStatus: string implements EstadoVisible
{
    case Borrador = 'draft';
    case Reservada = 'reserved';
    case Vencida = 'expired';
    case Cancelada = 'cancelled';
    case Finalizada = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Reservada => 'Reservada',
            self::Vencida => 'Reserva vencida',
            self::Cancelada => 'Cancelada',
            self::Finalizada => 'Finalizada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Borrador => 'gray',
            self::Reservada => 'warning',
            self::Vencida => 'danger',
            self::Cancelada => 'danger',
            self::Finalizada => 'success',
        };
    }

    /** El cliente todavía puede modificar la inscripción. */
    public function esEditablePorElCliente(): bool
    {
        return $this === self::Borrador;
    }

    /** La orden tiene cupos tomados que deben devolverse si muere. */
    public function ocupaCupos(): bool
    {
        return in_array($this, [self::Reservada, self::Finalizada], true);
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
