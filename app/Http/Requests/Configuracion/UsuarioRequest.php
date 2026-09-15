<?php

namespace App\Http\Requests\Configuracion;

use App\Enums\Rol;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * Un solo request para las cuatro cosas que se hacen con un usuario: crearlo,
 * corregir sus datos, cambiar su acceso o asignarle una contraseña. Cada
 * bloque de la ficha manda solo sus campos.
 */
class UsuarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        $usuario = $this->usuario();

        return $usuario === null
            ? ($this->user()?->can('create', User::class) ?? false)
            : ($this->user()?->can('update', $usuario) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $creando = $this->usuario() === null;
        $requerido = $creando ? 'required' : 'sometimes';

        return [
            'name' => [$requerido, 'string', 'max:255'],
            'email' => [$requerido, 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->usuario())],
            'password' => [$requerido, 'string', Password::defaults()],
            // Sin rol no entra al panel: se exige al menos uno.
            'roles' => [$requerido, 'array', 'min:1'],
            'roles.*' => [Rule::enum(Rol::class)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Nadie se deja a sí mismo afuera: sin esto, un administrador podía
     * desactivarse o quitarse el rol y el sistema quedaba sin quien lo arregle.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->usuario()?->is($this->user())) {
                    return;
                }

                if ($this->has('is_active') && ! $this->boolean('is_active')) {
                    $validator->errors()->add('is_active', 'No puedes desactivar tu propia cuenta.');
                }

                if ($this->has('roles') && ! in_array(Rol::Administrador->value, (array) $this->input('roles'), true)) {
                    $validator->errors()->add('roles', 'No puedes quitarte el rol de Administrador a ti mismo.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'roles.required' => 'Elige al menos un rol: sin rol, la persona no puede entrar.',
            'roles.min' => 'Elige al menos un rol: sin rol, la persona no puede entrar.',
            'email.unique' => 'Ya hay un usuario con ese correo.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'email' => 'correo',
            'password' => 'contraseña',
        ];
    }

    private function usuario(): ?User
    {
        /** @var User|null */
        return $this->route('user');
    }
}
