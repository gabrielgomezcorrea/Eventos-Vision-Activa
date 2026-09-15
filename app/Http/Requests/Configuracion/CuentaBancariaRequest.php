<?php

namespace App\Http\Requests\Configuracion;

use App\Models\BankAccount;
use App\Rules\RutValido;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CuentaBancariaRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var BankAccount|null $cuenta */
        $cuenta = $this->route('bankAccount');

        return $cuenta === null
            ? ($this->user()?->can('create', BankAccount::class) ?? false)
            : ($this->user()?->can('update', $cuenta) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var BankAccount|null $cuenta */
        $cuenta = $this->route('bankAccount');

        return [
            'label' => ['required', 'string', 'max:255'],
            'holder_name' => ['required', 'string', 'max:255'],
            'holder_rut' => ['nullable', 'string', 'max:20', new RutValido],
            'bank_name' => ['required', 'string', Rule::in(self::permitidos('bancos', $cuenta?->bank_name))],
            'account_type' => ['nullable', 'string', Rule::in(self::permitidos('tipos_de_cuenta', $cuenta?->account_type))],
            'account_number' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'payment_instructions' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'label' => 'nombre para reconocerla',
            'holder_name' => 'titular',
            'holder_rut' => 'RUT del titular',
            'bank_name' => 'banco',
            'account_type' => 'tipo de cuenta',
            'account_number' => 'número de cuenta',
            'email' => 'correo para avisos de pago',
            'payment_instructions' => 'instrucciones de pago',
        ];
    }

    /**
     * Los valores de la lista más el que la cuenta ya tenía guardado: una
     * cuenta cargada antes de existir la lista se sigue pudiendo editar.
     *
     * @return array<int, string>
     */
    private static function permitidos(string $lista, ?string $actual): array
    {
        return array_values(array_filter([...config("cuentas_bancarias.{$lista}"), $actual]));
    }
}
