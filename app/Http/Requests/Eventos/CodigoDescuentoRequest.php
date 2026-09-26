<?php

namespace App\Http\Requests\Eventos;

use App\Enums\TipoDescuento;
use App\Models\DiscountCode;
use App\Models\Event;
use App\Support\CodigoDeDescuento;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CodigoDescuentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', DiscountCode::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $datos = ['code' => CodigoDeDescuento::normalizar($this->input('code'))];

        // "Descuento completo" no es un tipo aparte: se guarda como 100%.
        if ($this->input('type') === 'full') {
            $datos += ['type' => TipoDescuento::Porcentaje->value, 'value' => 100];
        }

        $this->merge($datos);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        /** @var Event $evento */
        $evento = $this->route('event');

        return [
            'code' => [
                ...CodigoDeDescuento::regla($evento),
                Rule::unique('discount_codes', 'code')->where('event_id', $evento->getKey()),
            ],
            'type' => ['required', Rule::enum(TipoDescuento::class)],
            'value' => ['required', 'integer', 'min:1', $this->input('type') === 'percent' ? 'max:100' : 'max:100000000'],
            'max_people' => ['required', 'integer', 'min:1', 'max:99'],
            'expires_on' => ['required', 'date', 'after_or_equal:today'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.unique' => 'Ya existe ese código en este evento.',
            'value.max' => $this->input('type') === 'percent'
                ? 'Un porcentaje no puede pasar de 100.'
                : 'El monto es demasiado alto.',
            'max_people.max' => 'El máximo es 99 personas.',
            'expires_on.required' => 'Indica hasta qué fecha vale el código.',
            'expires_on.after_or_equal' => 'La fecha no puede ser anterior a hoy.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'code' => 'código',
            'type' => 'tipo de descuento',
            'value' => 'valor del descuento',
            'max_people' => 'máximo de personas',
            'expires_on' => 'fecha de vencimiento',
        ];
    }
}
