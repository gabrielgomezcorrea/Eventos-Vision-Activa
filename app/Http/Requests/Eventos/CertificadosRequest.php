<?php

namespace App\Http\Requests\Eventos;

use App\Models\Certificate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CertificadosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Certificate::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'certificados' => ['required', 'array', 'max:'.config('certificados.files_per_request')],
            'certificados.*' => ['file', 'mimes:pdf', 'max:'.config('certificados.max_file_kb')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $mb = (int) (config('certificados.max_file_kb') / 1024);

        return [
            'certificados.required' => 'Elige al menos un certificado en PDF.',
            'certificados.max' => 'Se suben de a '.config('certificados.files_per_request').' archivos por vez.',
            'certificados.*.mimes' => 'Solo se aceptan certificados en PDF.',
            'certificados.*.max' => "Un certificado no puede pesar más de {$mb} MB.",
            'certificados.*.uploaded' => "No se pudo subir uno de los archivos. Revisa que no pese más de {$mb} MB.",
        ];
    }
}
