<?php

namespace App\Http\Requests\Inscripciones;

use App\Enums\Permiso;
use App\Models\Event;
use App\Support\ReglasDeContacto;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Inscripción iniciada por el equipo: evento y correo de quien hace el trámite. */
class CrearInscripcionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permiso::GestionarOrdenes->value) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(ReglasDeContacto::limpiar($this->only(['email'])));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'event_id' => ['required', Rule::exists('events', 'id'), function (string $atributo, mixed $valor, \Closure $falla): void {
                if (! Event::query()->whereKey($valor)->first()?->admiteInscripciones()) {
                    $falla('Ese evento no recibe inscripciones.');
                }
            }],
            'email' => ReglasDeContacto::correo(),
            'notify_client' => ['sometimes', 'boolean'],
        ];
    }

    public function evento(): Event
    {
        return Event::findOrFail($this->integer('event_id'));
    }

    public function avisarAlCliente(): bool
    {
        return $this->boolean('notify_client', true);
    }

    public function correo(): string
    {
        return mb_strtolower(str_replace(' ', '', (string) $this->validated('email')));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['event_id' => 'evento', 'email' => 'correo'];
    }
}
