<?php

namespace App\Exceptions;

use RuntimeException;

/** El código de descuento que escribió el cliente no se puede usar; el mensaje dice por qué. */
class CodigoNoAplicable extends RuntimeException
{
    public static function porque(string $motivo): self
    {
        return new self($motivo);
    }
}
