<?php

namespace App\Http\Requests\Eventos;

use App\Models\DiscountCode;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Lo que se puede cambiar de un código ya creado: el tope, la fecha y si sirve.
 * El código y el valor no: quien ya lo recibió esperaba ese descuento.
 */
class ActualizarCodigoDescuentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', DiscountCode::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        /** @var DiscountCode $codigo */
        $codigo = $this->route('discountCode');

        return [
            // Bajar el tope por debajo de lo ya usado dejaría un código "sobrepasado".
            'max_people' => ['sometimes', 'required', 'integer', 'min:'.max(1, $codigo->used_people), 'max:99'],
            'expires_on' => ['sometimes', 'required', 'date', 'after_or_equal:today'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'max_people.min' => 'No puede ser menos que las personas que ya lo usaron.',
            'max_people.max' => 'El máximo es 99 personas.',
            'expires_on.after_or_equal' => 'La fecha no puede ser anterior a hoy.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'max_people' => 'máximo de personas',
            'expires_on' => 'fecha de vencimiento',
        ];
    }
}
