<?php

namespace App\Http\Requests\Inscripciones;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * El motivo es obligatorio: queda en la auditoría y es lo único que explica
 * después por qué esos cupos se liberaron.
 */
class CancelarOrdenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('order')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['motivo' => 'motivo de la cancelación'];
    }
}
