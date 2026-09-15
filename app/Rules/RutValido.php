<?php

namespace App\Rules;

use App\Support\Rut;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida el RUT solo cuando viene informado. Un campo vacío es válido:
 * el RUT es opcional en todo el sistema.
 */
class RutValido implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        if (! Rut::esValido((string) $value)) {
            $fail('El :attribute no es válido. Revise el número y el dígito verificador.');
        }
    }
}
