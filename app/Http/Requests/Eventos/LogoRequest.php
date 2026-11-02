<?php

namespace App\Http\Requests\Eventos;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class LogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', $this->route('event'));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'logo' => ['required', 'image', 'mimes:'.implode(',', Event::LOGO_FORMATOS), 'max:'.(Event::LOGO_MAX_MB * 1024)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'logo.required' => 'Elige la imagen del logo.',
            'logo.image' => 'El archivo debe ser una imagen.',
            'logo.mimes' => 'El logo debe ser JPG, PNG o WEBP.',
            'logo.max' => 'El logo no puede pesar más de '.Event::LOGO_MAX_MB.' MB.',
        ];
    }
}
