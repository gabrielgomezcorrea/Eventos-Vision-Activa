<?php

namespace App\Enums;

/**
 * Cómo se expresa un tramo de descuento: porcentaje o rebaja fija.
 *
 * Los dos porque así se negocia: unas veces "un 10% si vienen cinco" y otras
 * "les dejo $50.000 menos".
 */
enum TipoDescuento: string
{
    case Porcentaje = 'percent';
    case Monto = 'amount';

    public function label(): string
    {
        return match ($this) {
            self::Porcentaje => 'Porcentaje',
            self::Monto => 'Monto fijo',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $t): array => [$t->value => $t->label()])
            ->all();
    }
}
