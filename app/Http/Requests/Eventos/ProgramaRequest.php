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
                'mimes:pdf,doc,docx',
                'max:'.(EventAttachment::MAX_MB * 1024),
                function (string $atributo, mixed $valor, callable $falla): void {
                    // El tope se comprueba en el servidor y no solo en el
                    // botón: un formulario reenviado se salta el botón.
                    if ($this->evento()->attachments()->count() >= EventAttachment::MAXIMO) {
                        $falla('No puedes adjuntar más de '.EventAttachment::MAXIMO.' archivos. Quita uno antes de subir otro.');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'programa.mimes' => 'El archivo debe ser un PDF o un documento de Word.',
            'programa.max' => 'El archivo no puede pesar más de '.EventAttachment::MAX_MB.' MB.',
        ];
    }

    private function evento(): Event
    {
        /** @var Event */
        return $this->route('event');
    }
}
