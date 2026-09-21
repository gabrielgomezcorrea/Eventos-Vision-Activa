<?php

namespace App\Enums;

/**
 * Cómo se cuentan los participantes para el tramo de descuento cuando la
 * inscripción tiene más de un colegio (conjunto).
 *
 * Es plata, así que lo elige quien configura el evento, no el sistema.
 */
enum ModoConteoDescuento: string
{
    case PorColegio = 'per_school';
    case PorConjunto = 'per_group';

    public function label(): string
    {
        return match ($this) {
            self::PorColegio => 'Descuento por colegio',
            self::PorConjunto => 'Descuento por compra grande',
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::PorColegio => 'Cada colegio cuenta sus propios participantes, se inscriba solo o junto a otros.',
            self::PorConjunto => 'Se suman los participantes de todos los colegios de la misma inscripción, así que un sostenedor con varios colegios alcanza un descuento mayor.',
        };
    }

    /** @return array<string, array{label: string, descripcion: string}> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $modo): array => [$modo->value => [
                'label' => $modo->label(),
                'descripcion' => $modo->descripcion(),
            ]])
            ->all();
    }
}
