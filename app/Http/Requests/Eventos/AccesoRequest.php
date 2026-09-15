<?php

namespace App\Http\Requests\Eventos;

use App\Models\Event;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Un acceso declara qué jornadas incluye, no cuántos cupos consume: cada
 * jornada marcada descuenta un cupo, que es lo que pasa siempre.
 */
class AccesoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->evento()) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'integer', 'min:0', 'max:100000000'],
            // El anticipado no puede ser mayor que el normal: sería un recargo
            // por inscribirse temprano, que es justo lo contrario.
            'early_price' => ['nullable', 'integer', 'min:0', 'lt:price', 'required_with:early_until'],
            'early_until' => ['nullable', 'date', 'required_with:early_price'],
            'sessions' => ['required', 'array', 'min:1'],
            // Solo jornadas de este mismo evento: marcar una ajena descontaría
            // cupos de otro seminario.
            'sessions.*' => ['integer', Rule::exists('event_sessions', 'id')->where('event_id', $this->evento()->getKey())],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
            'wristband_label' => ['nullable', 'string', 'max:255'],
            'wristband_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sessions.required' => 'Marca al menos una jornada: sin jornadas no hay de dónde descontar cupos.',
            'sessions.min' => 'Marca al menos una jornada: sin jornadas no hay de dónde descontar cupos.',
            'sessions.*.exists' => 'Esa jornada no pertenece a este evento.',
            'early_price.lt' => 'El precio anticipado debe ser menor que el normal.',
            'early_price.required_with' => 'Indica el precio anticipado o borra la fecha.',
            'early_until.required_with' => 'Indica hasta cuándo rige el precio anticipado.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'price' => 'valor',
            'description' => 'descripción',
            'wristband_label' => 'nombre de la pulsera',
            'wristband_color' => 'color',
        ];
    }

    private function evento(): Event
    {
        /** @var Event */
        return $this->route('event');
    }
}
