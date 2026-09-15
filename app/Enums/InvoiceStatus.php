<?php

namespace App\Enums;

/**
 * Estado de facturación de la orden. Se deriva de si existe un documento
 * registrado, no se guarda como columna: tener dos fuentes de verdad para lo
 * mismo termina en desincronización.
 */
enum InvoiceStatus: string implements EstadoVisible
{
    case Pendiente = 'pending';
    case Emitida = 'issued';

    public function label(): string
    {
        return match ($this) {
            self::Pendiente => 'Factura pendiente',
            self::Emitida => 'Factura emitida',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pendiente => 'warning',
            self::Emitida => 'success',
        };
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
