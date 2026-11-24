<?php

namespace App\Enums;

/**
 * Life of an uploaded certificate: matched to a person, waiting its turn in the
 * spaced sending, and sent or failed.
 */
enum CertificateStatus: string implements EstadoVisible
{
    case PorRevisar = 'review';
    case Listo = 'ready';
    case EnCola = 'queued';
    case Enviando = 'sending';
    case Enviado = 'sent';
    case Fallido = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::PorRevisar => 'Por revisar',
            self::Listo => 'Listo para enviar',
            self::EnCola => 'Esperando su turno',
            self::Enviando => 'Enviando',
            self::Enviado => 'Enviado',
            self::Fallido => 'No se pudo enviar',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PorRevisar => 'warning',
            self::Listo => 'info',
            self::EnCola, self::Enviando => 'gray',
            self::Enviado => 'success',
            self::Fallido => 'danger',
        };
    }

    /** Only before it leaves (or after it failed) can the person be changed or the file removed. */
    public function seEdita(): bool
    {
        return in_array($this, [self::PorRevisar, self::Listo, self::Fallido], true);
    }
}
