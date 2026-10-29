<?php

namespace App\Http\Requests\Solicitudes;

use App\Models\ProgramRequest;
use App\Support\Forms\ProgramFormField;
use App\Support\ReglasDeContacto;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Datos de quien pidió el programa, completados o corregidos desde el panel.
 * El correo no se edita: de él dependen el enlace estable y la unión con su
 * inscripción. Todo es opcional porque una solicitud directa nace solo con correo.
 */
class ActualizarSolicitudRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ProgramRequest $solicitud */
        $solicitud = $this->route('programRequest');

        return $this->user()?->can('update', $solicitud) ?? false;
    }

    /** Same cleanup and rules as the public form. */
    protected function prepareForValidation(): void
    {
        $this->merge(ReglasDeContacto::limpiar($this->only(['first_name', 'last_name', 'position', 'position_otro', 'institution'])));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'first_name' => ReglasDeContacto::nombres(false),
            'last_name' => ReglasDeContacto::apellidos(false),
            'phone' => ReglasDeContacto::telefono(false),
            'position' => ReglasDeContacto::cargo(false, ProgramFormField::CARGOS),
            'position_otro' => ReglasDeContacto::cargoOtro('position'),
            'institution' => ReglasDeContacto::establecimiento(false),
        ];
    }

    /** @return array<string, mixed> */
    public function datosNormalizados(): array
    {
        return ReglasDeContacto::normalizar($this->validated(), [
            'first_name' => 'nombre',
            'last_name' => 'nombre',
            'phone' => 'telefono',
            'position' => 'cargo',
            'institution' => 'texto',
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'first_name' => 'nombre',
            'last_name' => 'apellidos',
            'phone' => 'teléfono',
            'position' => 'cargo',
            'position_otro' => 'cargo',
            'institution' => 'institución',
        ];
    }
}
