<?php

namespace App\Http\Requests\Eventos;

use App\Enums\EventModality;
use App\Enums\ReservationDurationUnit;
use App\Models\Event;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cada sección de la ficha guarda solo sus campos, así que todas las reglas
 * son `sometimes`: se valida lo que llega y nada más.
 */
class ActualizarEventoRequest extends FormRequest
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
        $evento = $this->evento();

        /** @var array<string, array<int, string>> $chile */
        $chile = config('chile');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:120', CrearEventoRequest::REGLA_NOMBRE],
            'modality' => ['sometimes', 'required', Rule::enum(EventModality::class)],
            'starts_on' => ['sometimes', 'required', 'date'],
            // Entre 1 hora y 30 días: avisar con más de un mes de antelación no
            // es un recordatorio, es otro correo más.
            'reminder_hours_before' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:720'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', 'alpha_dash', Rule::unique('events', 'slug')->ignore($evento)],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],

            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'region' => ['sometimes', 'nullable', Rule::in(array_keys($chile))],
            'commune' => ['sometimes', 'nullable', Rule::in($chile[$this->input('region')] ?? [])],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],

            'reservation_duration_value' => ['sometimes', 'required', 'integer', 'min:1', 'max:365'],
            'reservation_duration_unit' => ['sometimes', 'required', Rule::enum(ReservationDurationUnit::class)],
            'replacement_deadline' => ['sometimes', 'nullable', 'date'],

            // Una cuenta desactivada deja de ofrecerse, pero el evento que ya
            // la tenía puede seguir guardándose sin que lo obliguen a cambiarla.
            'bank_account_id' => ['sometimes', 'nullable', Rule::exists('bank_accounts', 'id')->where(
                fn (Builder $query) => $query->where('is_active', true)->orWhere('id', $evento->bank_account_id)
            )],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'Usa solo letras, números y signos de puntuación normales.',
            'commune.in' => 'Elige una comuna de la región seleccionada.',
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
            'reminder_hours_before' => 'aviso antes de vencer',
            'slug' => 'identificador en la URL',
            'description' => 'descripción',
            'location' => 'recinto',
            'address' => 'dirección',
            'region' => 'región',
            'commune' => 'comuna',
            'city' => 'ciudad',
            'reservation_duration_value' => 'tiempo para pagar',
            'reservation_duration_unit' => 'unidad del plazo',
            'replacement_deadline' => 'fecha límite de reemplazos',
            'bank_account_id' => 'cuenta',
        ];
    }

    private function evento(): Event
    {
        /** @var Event */
        return $this->route('event');
    }
}
