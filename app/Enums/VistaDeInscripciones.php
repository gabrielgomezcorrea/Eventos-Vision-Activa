<?php

namespace App\Enums;

/**
 * The cards on top of Inscripciones. Every order falls in exactly one, except
 * the empty drafts (a link opened with nothing written, often by the mail
 * provider's link scanner), which are not enrollments and are only counted.
 * Rules live in `Order::scopeEnVista()`.
 */
enum VistaDeInscripciones: string implements EstadoVisible
{
    case PorPagar = 'por_pagar';
    case EnRevision = 'en_revision';
    case EnProceso = 'en_proceso';
    case Pagadas = 'pagadas';
    case VencidasYCanceladas = 'vencidas';

    public function label(): string
    {
        return match ($this) {
            self::PorPagar => 'Por pagar',
            self::EnRevision => 'Pago en revisión',
            self::EnProceso => 'En proceso',
            self::Pagadas => 'Pagadas',
            self::VencidasYCanceladas => 'Vencidas y canceladas',
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::PorPagar => 'Confirmadas que aún no pagan todo.',
            self::EnRevision => 'Con un comprobante esperando a Contabilidad.',
            self::EnProceso => 'Se están llenando y aún no se confirman.',
            self::Pagadas => 'Pagadas por completo, invitados incluidos.',
            self::VencidasYCanceladas => 'Perdieron sus cupos.',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PorPagar => 'danger',
            self::EnRevision => 'info',
            self::EnProceso => 'gray',
            self::Pagadas => 'success',
            self::VencidasYCanceladas => 'warning',
        };
    }
}
