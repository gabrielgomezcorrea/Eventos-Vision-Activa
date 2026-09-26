<?php

namespace App\Http\Requests\Eventos;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UbicacionesBannerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', $this->route('event'));
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return collect(array_keys(Event::UBICACIONES_BANNER))
            ->mapWithKeys(fn (string $ubicacion): array => [$ubicacion => ['required', 'boolean']])
            ->all();
    }

    /** Ubicaciones marcadas, en el orden fijo del evento. @return list<string> */
    public function elegidas(): array
    {
        return array_values(array_filter(
            array_keys(Event::UBICACIONES_BANNER),
            fn (string $ubicacion): bool => $this->boolean($ubicacion),
        ));
    }
}
