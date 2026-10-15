<?php

namespace App\Exceptions;

use RuntimeException;

/** El cliente pidió un cambio que su inscripción ya no admite. */
class CambioNoPermitido extends RuntimeException
{
    public static function porque(string $motivo): self
    {
        return new self($motivo);
    }
}
