<?php

namespace App\Http\Requests\Eventos;

use App\Enums\EventStatus;
use App\Models\Event;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Publicar queda bloqueado mientras falte algo, diciendo qué falta, en vez de
 * dejar que lo descubra el primer cliente que intente inscribirse.
 */
class CambiarEstadoEventoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->evento()) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(EventStatus::class)],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('status') !== EventStatus::Publicado->value) {
                    return;
                }

                $falta = $this->evento()->loQueFaltaParaPublicar();

                if ($falta !== []) {
                    $validator->errors()->add('status', 'Para publicar falta cargar '.self::enumerar($falta).'.');
                }
            },
        ];
    }

    /** @param  array<int, string>  $items */
    public static function enumerar(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }

        $ultimo = array_pop($items);

        return implode(', ', $items).' y '.$ultimo;
    }

    private function evento(): Event
    {
        /** @var Event */
        return $this->route('event');
    }
}
