<?php

namespace App\Http\Requests\Eventos;

use App\Enums\EventModality;
use App\Models\Event;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Crear pide lo mínimo: nombre, modalidad y fecha. El identificador de la URL y
 * el estado no se preguntan; el resto se completa en la ficha cuando se sabe.
 *
 * La fecha entra acá y no después porque es lo primero que pregunta cualquiera
 * y porque las jornadas se validan contra ella.
 */
class CrearEventoRequest extends FormRequest
{
    /** El freno mínimo para que nadie escriba cualquier cosa como nombre. */
    public const REGLA_NOMBRE = 'regex:/^[\pL\pN\s.,:;()¿?¡!\/&+\'"«»-]+$/u';

    public function authorize(): bool
    {
        return $this->user()?->can('create', Event::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', self::REGLA_NOMBRE],
            'modality' => ['required', Rule::enum(EventModality::class)],
            'starts_on' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'Usa solo letras, números y signos de puntuación normales.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre del evento',
            'modality' => 'modalidad',
            'starts_on' => 'fecha del evento',
            'description' => 'descripción',
        ];
    }
}
