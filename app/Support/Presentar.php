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
    /** Fecha corta: "10 Oct. 2026". */
    public static function fecha(\DateTimeInterface $fecha): string
    {
        $meses = ['Ene.', 'Feb.', 'Mar.', 'Abr.', 'May.', 'Jun.', 'Jul.', 'Ago.', 'Sep.', 'Oct.', 'Nov.', 'Dic.'];

        return $fecha->format('j').' '.$meses[(int) $fecha->format('n') - 1].' '.$fecha->format('Y');
    }

    /** Fecha y hora en una línea: "10 Oct. 2026 - 16:43". */
    public static function fechaYHora(\DateTimeInterface $fecha): string
    {
        return self::fecha($fecha).' - '.$fecha->format('H:i');
    }

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
