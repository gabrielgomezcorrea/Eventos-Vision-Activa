<?php

namespace App\Enums;

/**
 * Cómo paga el cliente. Al contado transfiere y sube el comprobante; a crédito
 * la inscripción hace de orden de compra: no adjunta nada y Contabilidad solo
 * aprueba. Los pagos anteriores a esta opción son todos al contado.
 */
enum PaymentMethod: string
{
    case Transferencia = 'bank_transfer';
    case Credito = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::Transferencia => 'Pago al contado',
            self::Credito => 'Pago a crédito',
        };
    }

    /** Ambos pasan por Contabilidad: a crédito no hay archivo, pero igual se aprueba. */
    public function requiereValidacionManual(): bool
    {
        return true;
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
