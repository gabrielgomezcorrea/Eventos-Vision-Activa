<?php

namespace App\Http\Requests\Inscripciones;

use App\Enums\Permiso;
use App\Models\Event;
use App\Rules\RutValido;
use App\Support\Forms\ProgramFormField;
use App\Support\ReglasDeContacto;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Invitado sin costo. Solo Administración: es entrada liberada al evento, la
 * misma razón por la que solo ese rol abre credenciales completas.
 */
class RegistrarInvitadoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permiso::VerCredenciales->value) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(ReglasDeContacto::limpiar($this->only([
            'first_name', 'last_name', 'position', 'position_otro', 'email', 'establecimiento',
        ])));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $evento = $this->evento();

        return [
            'event_id' => ['required', Rule::exists('events', 'id')],
            'first_name' => ReglasDeContacto::nombres(),
            'last_name' => ReglasDeContacto::apellidos(),
            'rut' => ['required', 'string', 'max:20', new RutValido],
            'email' => ReglasDeContacto::correo(),
            'phone' => ReglasDeContacto::telefono(false),
            'position' => ReglasDeContacto::cargo(true, $this->evento()?->cargosDeParticipante() ?? ProgramFormField::CARGOS_PARTICIPANTE),
            'position_otro' => ReglasDeContacto::cargoOtro('position'),
            'establecimiento' => ReglasDeContacto::establecimiento(false),
            'access_type_id' => ['required', Rule::in($evento?->accessTypes()->where('is_active', true)->pluck('id')->all() ?? [])],
        ];
    }

    /**
     * Data as it must be stored: capitalized names, normalized phone and the
     * typed position instead of "Otro".
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
            'phone' => 'telefono',
            'establecimiento' => 'nombre',
        ]);
    }

    public function evento(): ?Event
    {
        return Event::find($this->input('event_id'));
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
            'establecimiento' => 'establecimiento',
            'access_type_id' => 'tipo de acceso',
            'event_id' => 'evento',
        ];
    }
}
