<?php

namespace App\Exceptions;

use RuntimeException;

class OrdenNoConfirmable extends RuntimeException
{
    public static function porque(string $motivo): self
    {
        return new self($motivo);
    }

    public static function porqueYaNoEsBorrador(): self
    {
        return new self('Esta inscripción ya fue confirmada.');
    }
}
