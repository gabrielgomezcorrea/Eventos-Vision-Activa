<?php

namespace App\Http\Requests\Inscripciones;

use App\Models\Order;
use App\Support\ReglasDeContacto;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Corregir el correo del responsable después de confirmar. El cliente solo lo
 * cambia en borrador: después, un correo mal escrito dejaría el pago y las
 * credenciales llegando a ninguna parte, y lo arregla Coordinación.
 */
class CorregirCorreoDelResponsableRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Order $orden */
        $orden = $this->route('order');

        return $this->user()?->can('update', $orden) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(ReglasDeContacto::limpiar($this->only(['responsible_email'])));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['responsible_email' => ReglasDeContacto::correo()];
    }

    public function correo(): string
    {
        return ReglasDeContacto::normalizar($this->validated(), ['responsible_email' => 'correo'])['responsible_email'];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['responsible_email' => 'correo'];
    }
}
