<?php

namespace App\Http\Requests\Eventos;

use App\Enums\TipoDescuento;
use App\Models\DiscountTier;
use App\Models\Event;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TramoDescuentoRequest extends FormRequest
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
            // Desde dos: un "descuento desde 1 participante" es bajar el precio
            // del acceso, y para eso está el precio del acceso.
            'min_participants' => [
                'required', 'integer', 'min:2', 'max:999',
                Rule::unique('discount_tiers', 'min_participants')
                    ->where('event_id', $this->evento()->getKey())
                    ->ignore($this->tramo()?->getKey()),
            ],
            'type' => ['required', Rule::enum(TipoDescuento::class)],
            'value' => ['required', 'integer', 'min:1', $this->input('type') === 'percent' ? 'max:100' : 'max:100000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'min_participants.unique' => 'Ya existe un tramo para esa cantidad de participantes.',
            'min_participants.min' => 'El tramo parte desde 2 participantes.',
            'value.max' => $this->input('type') === 'percent'
                ? 'Un porcentaje no puede pasar de 100.'
                : 'El monto es demasiado alto.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'min_participants' => 'cantidad de participantes',
            'type' => 'tipo de descuento',
            'value' => 'valor del descuento',
        ];
    }

    private function tramo(): ?DiscountTier
    {
        /** @var DiscountTier|null */
        return $this->route('tier');
    }

    private function evento(): Event
    {
        /** @var Event */
        return $this->route('event');
    }
}
