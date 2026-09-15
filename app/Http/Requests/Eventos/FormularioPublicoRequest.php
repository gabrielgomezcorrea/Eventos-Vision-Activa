<?php

namespace App\Http\Requests\Eventos;

use App\Models\Event;
use App\Support\Forms\ProgramFormField;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class FormularioPublicoRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Event $evento */
        $evento = $this->route('event');

        return $this->user()?->can('update', $evento) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'campos' => ['required', 'array', 'min:1'],
            'campos.*.key' => ['nullable', 'string', 'max:100'],
            'campos.*.label' => ['required', 'string', 'max:255'],
            'campos.*.type' => ['required', Rule::in(array_keys(ProgramFormField::TIPOS))],
            'campos.*.required' => ['boolean'],
            'campos.*.placeholder' => ['nullable', 'string', 'max:255'],
            'campos.*.options' => ['nullable', 'array'],
            'campos.*.options.*' => ['string', 'max:255'],
            'consent_text' => ['nullable', 'string', 'max:1000'],
            'program_email_intro' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var array<int, array<string, mixed>> $campos */
                $campos = $this->input('campos', []);

                // Sin correo no hay a dónde mandar el programa.
                if (! collect($campos)->contains(fn (array $campo): bool => ($campo['key'] ?? null) === ProgramFormField::OBLIGATORIO_SIEMPRE)) {
                    $validator->errors()->add('campos', 'El correo no se puede quitar: es por donde llega el programa.');
                }

                // Una lista sin opciones deja un desplegable vacío en la web.
                foreach ($campos as $indice => $campo) {
                    $esJornada = ($campo['key'] ?? null) === 'access_type_id';

                    if (($campo['type'] ?? null) === 'select' && ! $esJornada && empty($campo['options'])) {
                        $validator->errors()->add("campos.{$indice}.options", 'Agrega al menos una opción a la lista.');
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'campos.*.label.required' => 'Escribe el nombre de la pregunta.',
        ];
    }
}
