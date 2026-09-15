<?php

namespace App\Enums;

/**
 * La inscripción particular no es un flujo aparte: es el mismo flujo con los
 * datos precargados desde el responsable y el establecimiento opcional.
 */
enum OrderKind: string
{
    case Institucional = 'institutional';
    case Particular = 'individual';

    public function label(): string
    {
        return match ($this) {
            self::Institucional => 'Institucional',
            self::Particular => 'Particular',
        };
    }

    public function requiereEstablecimiento(): bool
    {
        return $this === self::Institucional;
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
