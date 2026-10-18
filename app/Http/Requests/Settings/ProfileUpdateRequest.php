<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Nombre y correo, o las casillas de avisos: cada bloque manda lo suyo.
        $perfil = array_map(fn (array $reglas): array => ['sometimes', ...$reglas], $this->profileRules($this->user()->id));

        return [...$perfil, ...$this->user()->reglasDeAvisos()];
    }
}
