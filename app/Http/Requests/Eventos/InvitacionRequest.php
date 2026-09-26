<?php

namespace App\Http\Requests\Eventos;

use App\Models\Event;
use App\Models\Invitation;
use App\Support\Texto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvitacionRequest extends FormRequest
{
    public const MAXIMO_POR_ENVIO = 50;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Invitation::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        /** @var Event $evento */
        $evento = $this->route('event');

        return [
            'emails' => ['required', 'string', 'max:5000'],
            'access_type_id' => ['required', Rule::in($evento->accessTypes()->where('is_active', true)->pluck('id')->all())],
            'expires_on' => ['required', 'date', 'after_or_equal:today'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'emails.required' => 'Escribe al menos un correo.',
            'access_type_id.required' => 'Elige el acceso de la invitación.',
            'access_type_id.in' => 'Elige un acceso disponible.',
            'expires_on.required' => 'Indica hasta qué fecha vale la invitación.',
            'expires_on.after_or_equal' => 'La fecha no puede ser anterior a hoy.',
        ];
    }

    /**
     * Un correo por línea, separados también por coma o punto y coma. Sin
     * repetidos y en minúsculas.
     *
     * @return list<string>
     */
    public function correos(): array
    {
        $partes = preg_split('/[\s,;]+/', (string) $this->input('emails'), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_map(fn (string $c): string => (string) Texto::correo($c), $partes)));
    }

    /** Los que no parecen un correo, para marcarlos sin perder lo escrito. @return list<string> */
    public function correosInvalidos(): array
    {
        return array_values(array_filter(
            $this->correos(),
            fn (string $correo): bool => filter_var($correo, FILTER_VALIDATE_EMAIL) === false,
        ));
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                if ($this->correos() === []) {
                    return;
                }

                if (count($this->correos()) > self::MAXIMO_POR_ENVIO) {
                    $validator->errors()->add('emails', 'Son demasiados: envía hasta '.self::MAXIMO_POR_ENVIO.' por vez.');
                } elseif ($this->correosInvalidos() !== []) {
                    $validator->errors()->add('emails', 'Revisa estos correos: '.implode(', ', $this->correosInvalidos()).'.');
                }
            },
        ];
    }
}
