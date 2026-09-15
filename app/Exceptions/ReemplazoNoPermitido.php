<?php

namespace App\Exceptions;

use RuntimeException;

class ReemplazoNoPermitido extends RuntimeException
{
    public static function porque(string $motivo): self
    {
        return new self($motivo);
    }
}
