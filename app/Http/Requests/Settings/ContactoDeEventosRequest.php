<?php

namespace App\Http\Requests\Settings;

use App\Enums\Permiso;
use App\Support\Texto;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Solo Administración define a quién le escribe o llama un cliente con dudas.
 */
class ContactoDeEventosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permiso::GestionarUsuarios->value) ?? false;
    }

    /**
     * Se normaliza antes de validar: escrito con espacios, guiones o sin el
     * +56 igual sirve, y lo que se guarda es siempre la misma forma. Lo que no
     * sea un móvil chileno se rechaza.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('contacto_telefono')) {
            $this->merge([
                'contacto_telefono' => Texto::telefono($this->input('contacto_telefono'))
                    ?? $this->input('contacto_telefono'),
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'contacto_email' => ['nullable', 'email:rfc', 'max:255'],
            // Exacto: +56 9 y ocho dígitos, sin espacios ni nada más. Este
            // número se imprime en todos los correos que salen del sistema.
            'contacto_telefono' => ['nullable', 'string', 'regex:/^\+569\d{8}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'contacto_telefono.regex' => 'Debes seguir el formato +56912345678. Solo se aceptan celulares chilenos.',
            'contacto_email.email' => 'Revisa el correo: debe tener un arroba y un dominio.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'contacto_email' => 'correo de contacto',
            'contacto_telefono' => 'teléfono de contacto',
        ];
    }
}
