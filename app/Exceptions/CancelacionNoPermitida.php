<?php

namespace App\Exceptions;

use RuntimeException;

class CancelacionNoPermitida extends RuntimeException
{
    public static function porque(string $motivo): self
    {
        return new self($motivo);
    }
}
