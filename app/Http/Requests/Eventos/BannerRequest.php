<?php

namespace App\Http\Requests\Eventos;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class BannerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', $this->route('event'));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'banner' => [
                'required',
                'image',
                'mimes:'.implode(',', Event::BANNER_FORMATOS),
                'max:'.(Event::BANNER_MAX_MB * 1024),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'banner.required' => 'Elige la imagen del banner.',
            'banner.image' => 'El archivo debe ser una imagen.',
            'banner.mimes' => 'El banner debe ser JPG, PNG o WEBP. No se aceptan GIF ni videos.',
            'banner.max' => 'La imagen no puede pesar más de '.Event::BANNER_MAX_MB.' MB.',
        ];
    }
}
