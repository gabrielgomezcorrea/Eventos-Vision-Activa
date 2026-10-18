<?php

namespace App\Http\Requests\Solicitudes;

use App\Models\ProgramRequest;
use Illuminate\Foundation\Http\FormRequest;

class GuardarNotaRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ProgramRequest $solicitud */
        $solicitud = $this->route('programRequest');

        // Quien ve las solicitudes puede dejar notas: es su trabajo de seguimiento.
        return $this->user()?->can('view', $solicitud) ?? false;
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
