<?php

namespace App\Http\Requests\Inscripciones;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;

class GuardarNotaRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Order $orden */
        $orden = $this->route('order');

        // Quien ve la inscripción (también Contabilidad) puede dejar notas.
        return $this->user()?->can('view', $orden) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('body'))) {
            $this->merge(['body' => trim($this->input('body'))]);
        }
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'min:2', 'max:2000']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'body.required' => 'Escribe la nota (al menos 2 caracteres).',
            'body.min' => 'Escribe la nota (al menos 2 caracteres).',
            'body.max' => 'La nota no puede pasar de 2000 caracteres.',
        ];
    }
}
