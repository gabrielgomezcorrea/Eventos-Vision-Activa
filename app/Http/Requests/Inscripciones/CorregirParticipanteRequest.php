<?php

namespace App\Http\Requests\Inscripciones;

use App\Enums\ParticipantStatus;
use App\Models\Order;
use App\Models\Participant;
use App\Rules\RutValido;
use App\Support\ReglasDeContacto;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Corregir un dato mal escrito sin tocar acceso ni valor. Para cambiar de
 * persona está el reemplazo, que anula la credencial.
 */
class CorregirParticipanteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->orden()) ?? false;
    }

    /** Same cleanup and rules as the public form and enrollment. */
    protected function prepareForValidation(): void
    {
        $this->merge(ReglasDeContacto::limpiar($this->only(['first_name', 'last_name', 'position', 'position_otro', 'email'])));
    }

    /**
     * Validated data as it must be stored: capitalized names, lowercase email
     * and the typed text instead of "Otro".
     *
     * @return array<string, mixed>
     */
    public function datosNormalizados(): array
    {
        return ReglasDeContacto::normalizar($this->validated(), [
            'first_name' => 'nombre',
            'last_name' => 'nombre',
            'position' => 'cargo',
            'email' => 'correo',
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'first_name' => ReglasDeContacto::nombres(),
            'last_name' => ReglasDeContacto::apellidos(),
            'rut' => ['nullable', 'string', 'max:20', new RutValido],
            'position' => ReglasDeContacto::cargo(),
            'position_otro' => ReglasDeContacto::cargoOtro('position'),
            'email' => ReglasDeContacto::correo(),
            'establishment_id' => ['nullable', Rule::in($this->orden()->establishments()->pluck('establishments.id')->all())],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var Participant $participante */
                $participante = $this->route('participant');

                if ($participante->status === ParticipantStatus::Reemplazado) {
                    $validator->errors()->add('first_name', 'Esta persona fue reemplazada: sus datos ya no se corrigen.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'first_name' => 'nombre',
            'last_name' => 'apellidos',
            'rut' => 'RUT',
            'position' => 'cargo',
            'position_otro' => 'cargo',
            'email' => 'correo',
            'establishment_id' => 'establecimiento',
        ];
    }

    private function orden(): Order
    {
        /** @var Order */
        return $this->route('order');
    }
}
