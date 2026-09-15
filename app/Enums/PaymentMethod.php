<?php

namespace App\Enums;

/**
 * Medio de pago. El MVP solo usa transferencia manual, pero el enum existe
 * para que agregar un proveedor online más adelante no obligue a rehacer nada.
 */
enum PaymentMethod: string
{
    case Transferencia = 'bank_transfer';

    public function label(): string
    {
        return match ($this) {
            self::Transferencia => 'Transferencia bancaria',
        };
    }

    /** Los medios manuales requieren que alguien valide el comprobante. */
    public function requiereValidacionManual(): bool
    {
        return match ($this) {
            self::Transferencia => true,
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
