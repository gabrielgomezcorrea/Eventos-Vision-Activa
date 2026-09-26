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

    /** Invitado del evento: no paga, ocupa cupo y se acredita como cualquiera. */
    case Invitado = 'guest';

    /**
     * Tipos que puede elegir un cliente en el formulario público. El invitado
     * lo crea solo Administración: dejarlo aquí permitía inscribirse gratis.
     *
     * @return list<self>
     */
    public static function elegiblesPorElCliente(): array
    {
        return [self::Institucional, self::Particular];
    }

    public function label(): string
    {
        return match ($this) {
            self::Institucional => 'Institucional',
            self::Particular => 'Particular',
            self::Invitado => 'Invitado especial',
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
