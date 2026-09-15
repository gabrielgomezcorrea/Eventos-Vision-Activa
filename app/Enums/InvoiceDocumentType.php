<?php

namespace App\Enums;

enum InvoiceDocumentType: string
{
    case Factura = 'invoice';
    case Boleta = 'receipt';
    case NotaDeCredito = 'credit_note';

    public function label(): string
    {
        return match ($this) {
            self::Factura => 'Factura',
            self::Boleta => 'Boleta',
            self::NotaDeCredito => 'Nota de crédito',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
