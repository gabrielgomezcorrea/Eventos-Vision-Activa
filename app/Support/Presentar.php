<?php

namespace App\Support;

use App\Enums\EstadoVisible;
use BackedEnum;

/**
 * Forma común en que los estados viajan a las pantallas: valor, etiqueta y
 * color semántico. Así el frontend no repite la traducción de cada enum.
 */
class Presentar
{
    /**
     * @return array{value: string|int, label: string, color: string}
     */
    public static function estado(BackedEnum&EstadoVisible $estado): array
    {
        return [
            'value' => $estado->value,
            'label' => $estado->label(),
            'color' => $estado->color(),
        ];
    }
}
