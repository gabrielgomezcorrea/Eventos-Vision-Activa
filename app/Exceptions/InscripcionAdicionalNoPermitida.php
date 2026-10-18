<?php

namespace App\Exceptions;

use RuntimeException;

class InscripcionAdicionalNoPermitida extends RuntimeException
{
    public static function porque(string $motivo): self
    {
        return new self($motivo);
    }
}
