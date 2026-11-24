<?php

namespace App\Enums;

enum CertificateCampaignStatus: string implements EstadoVisible
{
    case Enviando = 'sending';
    case Pausada = 'paused';
    case Terminada = 'finished';

    public function label(): string
    {
        return match ($this) {
            self::Enviando => 'Envío en curso',
            self::Pausada => 'Envío pausado',
            self::Terminada => 'Envío terminado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Enviando => 'info',
            self::Pausada => 'warning',
            self::Terminada => 'success',
        };
    }
}
