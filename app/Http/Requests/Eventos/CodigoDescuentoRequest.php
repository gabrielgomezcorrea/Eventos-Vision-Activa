<?php

namespace App\Http\Requests\Eventos;

use App\Enums\TipoDescuento;
use App\Models\AccessType;
use App\Models\DiscountCode;
use App\Models\Event;
use App\Support\CodigoDeDescuento;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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

        // «Por jornada» no tiene valor propio: el precio va en cada fila.
        if ($this->input('type') === TipoDescuento::PorJornada->value) {
            $datos += ['value' => 0];
            $filas = [];

            foreach ($this->array('prices') as $fila) {
                if (! is_array($fila)) {
                    continue;
                }

                $filas[] = [
                    'access_type_id' => $fila['access_type_id'] ?? null,
                    // Pesos enteros: llegan con puntos o "$" si los copiaron de una lista.
                    'price' => isset($fila['price']) && is_scalar($fila['price'])
                        ? (preg_replace('/[^\d]/', '', (string) $fila['price']) ?? '')
                        : null,
                ];
            }

            $datos += ['prices' => $filas];
        }

        $this->merge($datos);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        /** @var Event $evento */
        $evento = $this->route('event');

        $porJornada = $this->input('type') === TipoDescuento::PorJornada->value;

        return [
            'code' => [
                ...CodigoDeDescuento::regla($evento),
                Rule::unique('discount_codes', 'code')->where('event_id', $evento->getKey()),
            ],
            'type' => ['required', Rule::enum(TipoDescuento::class)],
            'value' => $porJornada
                ? ['integer']
                : ['required', 'integer', 'min:1', $this->input('type') === 'percent' ? 'max:100' : 'max:100000000'],
            'prices' => $porJornada ? ['required', 'array', 'min:1'] : ['prohibited'],
            'prices.*.access_type_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('access_types', 'id')->where('event_id', $evento->getKey()),
            ],
            'prices.*.price' => ['required', 'integer', 'min:0', 'max:100000000'],
            'max_people' => ['required', 'integer', 'min:1', 'max:99'],
            'uses_per_person' => ['required', Rule::in(array_keys(DiscountCode::USOS_POR_PERSONA))],
            'expires_on' => ['required', 'date', 'after_or_equal:today'],
        ];
    }

    /**
     * El precio especial va de $0 al precio normal del acceso.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validador): void {
            if ($this->input('type') !== TipoDescuento::PorJornada->value || $validador->errors()->isNotEmpty()) {
                return;
            }

            /** @var array<int, array{access_type_id: int|string, price: int|string}> $filas */
            $filas = $this->array('prices');
            $accesos = AccessType::query()
                ->whereIn('id', array_column($filas, 'access_type_id'))
                ->get()
                ->keyBy('id');

            foreach ($filas as $i => $fila) {
                $acceso = $accesos->get((int) $fila['access_type_id']);

                if ($acceso !== null && (int) $fila['price'] > (int) $acceso->price) {
                    $validador->errors()->add(
                        "prices.{$i}.price",
                        'El precio especial no puede ser mayor que el precio normal de '.$acceso->name.' ($'.number_format((int) $acceso->price, 0, ',', '.').').',
                    );
                }
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'prices.required' => 'Agregue al menos una jornada con su precio.',
            'prices.min' => 'Agregue al menos una jornada con su precio.',
            'prices.*.access_type_id.distinct' => 'Ese acceso ya está en la lista.',
            'prices.*.access_type_id.required' => 'Elige el acceso.',
            'prices.*.access_type_id.exists' => 'Ese acceso no es de este evento.',
            'prices.*.price.required' => 'Escribe el precio.',
            'prices.*.price.integer' => 'El precio debe ser un número entero, sin puntos ni decimales.',
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
            'max_people' => 'personas que pueden usarlo',
            'uses_per_person' => 'usos por persona',
            'expires_on' => 'fecha de vencimiento',
        ];
    }
}
