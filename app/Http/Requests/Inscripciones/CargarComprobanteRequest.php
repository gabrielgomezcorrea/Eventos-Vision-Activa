<?php

namespace App\Http\Requests\Inscripciones;

use App\Enums\Permiso;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Comprobante cargado por el equipo cuando llegó por correo: así la orden
 * tiene un expediente único en vez de vivir en la bandeja de Gmail.
 */
class CargarComprobanteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permiso::CargarComprobante->value) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'payer_name' => ['nullable', 'string', 'max:255'],
            'proof' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'proof.required' => 'Adjunta el comprobante.',
            'proof.mimes' => 'El comprobante debe ser un PDF o una imagen (JPG, PNG o WEBP).',
            'proof.max' => 'El comprobante no puede pesar más de 10 MB.',
            'paid_on.before_or_equal' => 'La fecha de la transferencia no puede ser futura.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'amount' => 'monto informado',
            'paid_on' => 'fecha de la transferencia',
            'bank_name' => 'banco de origen',
            'payer_name' => 'nombre de quien pagó',
            'notes' => 'observaciones',
        ];
    }
}
