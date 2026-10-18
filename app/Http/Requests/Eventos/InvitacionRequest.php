<?php

namespace App\Http\Requests\Eventos;

use App\Models\Event;
use App\Models\Invitation;
use App\Support\ReglasDeContacto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvitacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Invitation::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(ReglasDeContacto::limpiar($this->only(['first_name', 'last_name', 'email'])));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        /** @var Event $evento */
        $evento = $this->route('event');

        return [
            'first_name' => ReglasDeContacto::nombres(),
            'last_name' => ReglasDeContacto::apellidos(),
            'email' => ReglasDeContacto::correo(),
            'access_type_id' => ['required', Rule::in($evento->accessTypes()->where('is_active', true)->pluck('id')->all())],
            'expires_on' => ['required', 'date', 'after_or_equal:today'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['first_name' => 'nombre', 'last_name' => 'apellidos', 'email' => 'correo'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'access_type_id.required' => 'Elige el acceso de la invitación.',
            'access_type_id.in' => 'Elige un acceso disponible.',
            'expires_on.required' => 'Indica hasta qué fecha vale la invitación.',
            'expires_on.after_or_equal' => 'La fecha no puede ser anterior a hoy.',
        ];
    }

    /**
     * Datos de la persona ya normalizados, listos para guardar.
     *
     * @return array{first_name: string, last_name: string, email: string}
     */
    public function persona(): array
    {
        $datos = ReglasDeContacto::normalizar($this->only(['first_name', 'last_name', 'email']), [
            'first_name' => 'nombre',
            'last_name' => 'nombre',
            'email' => 'correo',
        ]);

        return [
            'first_name' => (string) $datos['first_name'],
            'last_name' => (string) $datos['last_name'],
            'email' => (string) $datos['email'],
        ];
    }
}
