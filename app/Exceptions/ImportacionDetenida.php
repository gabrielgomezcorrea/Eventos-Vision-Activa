<?php

namespace App\Exceptions;

use RuntimeException;

/** An import that cannot continue; carries every reason in plain Spanish. Nothing is saved. */
class ImportacionDetenida extends RuntimeException
{
    /** @param  array<int, string>  $problemas */
    public function __construct(public readonly array $problemas)
    {
        parent::__construct(implode(' ', $problemas));
    }

    public static function porque(string ...$problemas): self
    {
        return new self(array_values($problemas));
    }
}
