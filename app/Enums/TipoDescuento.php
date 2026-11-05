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
    /** Solo para códigos: precio final por persona según su acceso. No vale en tramos. */
    case PorJornada = 'per_access';

    public function label(): string
    {
        return match ($this) {
            self::Porcentaje => 'Porcentaje',
            self::Monto => 'Monto fijo',
            self::PorJornada => 'Por jornada',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect([self::Porcentaje, self::Monto])
            ->mapWithKeys(fn (self $t): array => [$t->value => $t->label()])
            ->all();
    }
}
