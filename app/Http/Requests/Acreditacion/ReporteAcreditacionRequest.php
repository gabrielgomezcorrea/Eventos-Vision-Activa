<?php

namespace App\Http\Requests\Acreditacion;

use App\Enums\Permiso;
use Illuminate\Foundation\Http\FormRequest;

class ReporteAcreditacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permiso::VerReporteAcreditacion->value) ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'evento' => ['nullable', 'integer'],
            'estado' => ['nullable', 'in:llegaron,faltan'],
            'inscripcion' => ['nullable', 'integer'],
            'buscar' => ['nullable', 'string', 'max:120'],
        ];
    }
}
