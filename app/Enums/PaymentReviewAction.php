<?php

namespace App\Enums;

/**
 * Decisión de Contabilidad sobre un comprobante.
 */
enum PaymentReviewAction: string implements EstadoVisible
{
    case Aprobar = 'approved';
    case Observar = 'observed';
    case Rechazar = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Aprobar => 'Aprobado',
            self::Observar => 'Observado',
            self::Rechazar => 'Rechazado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Aprobar => 'success',
            self::Observar => 'warning',
            self::Rechazar => 'danger',
        };
    }

    /** Observar y rechazar exigen decirle al cliente qué pasó. */
    public function exigeComentario(): bool
    {
        return $this !== self::Aprobar;
    }

    public function estadoResultante(): PaymentStatus
    {
        return match ($this) {
            self::Aprobar => PaymentStatus::Aprobado,
            self::Observar => PaymentStatus::Observado,
            self::Rechazar => PaymentStatus::Rechazado,
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
