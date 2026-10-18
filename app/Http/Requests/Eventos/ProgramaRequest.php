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
            'programa' => [
                'required', 'file',
                // Only PDF: a Word file can be edited by whoever receives it.
                'mimes:pdf',
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
            'programa.mimes' => 'El programa debe ser un PDF.',
            'programa.max' => 'El archivo no puede pesar más de '.EventAttachment::MAX_MB.' MB.',
        ];
    }
}
