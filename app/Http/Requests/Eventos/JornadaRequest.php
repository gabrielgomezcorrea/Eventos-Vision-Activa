<?php

namespace App\Http\Requests\Eventos;

use App\Models\Event;
use App\Models\EventSession;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class JornadaRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            // Bajar la capacidad por debajo de lo ya vendido dejaría la jornada
            // sobrevendida sin que nadie lo note.
            'capacity' => ['nullable', 'integer', 'min:'.$this->cuposTomados()],
            // Una jornada nunca parte antes que el evento: la fecha del evento
            // es la que manda y es la que ve el cliente en el programa.
            'starts_at' => ['nullable', 'date', ...($this->fechaDelEvento() ? ['after_or_equal:'.$this->fechaDelEvento()] : [])],
            'ends_at' => ['nullable', 'date', ...($this->filled('starts_at') ? ['after:starts_at'] : [])],
            'location' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'capacity.min' => $this->cuposTomados() > 0
                ? 'Ya hay '.$this->cuposTomados().' cupos tomados en esta jornada: la capacidad no puede ser menor.'
                : 'Los cupos no pueden ser negativos.',
            'ends_at.after' => 'El término debe ser después del inicio.',
            'starts_at.after_or_equal' => 'El evento empieza el '
                .$this->evento()->starts_on?->format('d-m-Y')
                .'. Una jornada no puede ser antes de esa fecha.',
        ];
    }

    private function evento(): Event
    {
        /** @var Event */
        return $this->route('event');
    }

    /** Fecha del evento al que pertenece la jornada, si está definida. */
    private function fechaDelEvento(): ?string
    {
        return $this->evento()->starts_on?->format('Y-m-d');
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'capacity' => 'cupos',
            'starts_at' => 'inicio',
            'ends_at' => 'término',
            'location' => 'lugar',
        ];
    }

    private function cuposTomados(): int
    {
        /** @var EventSession|null $jornada */
        $jornada = $this->route('session');

        return $jornada->reserved_seats ?? 0;
    }
}
