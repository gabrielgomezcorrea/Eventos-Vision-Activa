<?php

namespace App\Http\Requests\Eventos;

use App\Models\Event;
use App\Models\EventAttachment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProgramaRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Event $evento */
        $evento = $this->route('event');

        return $this->user()?->can('update', $evento) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'programa' => ['required', 'array', 'max:10'],
            'programa.*' => [
                'file',
                'mimes:'.implode(',', EventAttachment::EXTENSIONES),
                'max:'.(EventAttachment::MAX_MB * 1024),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'programa.required' => 'Elige al menos un archivo.',
            'programa.*.mimes' => 'Ese tipo de archivo no se puede subir. Usa PDF, Word, Excel, PowerPoint o una imagen.',
            'programa.*.max' => 'Un archivo no puede pesar más de '.EventAttachment::MAX_MB.' MB.',
            'programa.*.uploaded' => 'No se pudo subir uno de los archivos. Revisa que no pese más de '.EventAttachment::MAX_MB.' MB.',
        ];
    }
}
