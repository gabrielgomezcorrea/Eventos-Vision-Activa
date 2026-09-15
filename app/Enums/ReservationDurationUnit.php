<?php

namespace App\Enums;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Unidad del plazo de reserva de cupos.
 *
 * El MVP opera en días corridos, pero la unidad es configurable por evento para
 * poder cambiar a días hábiles sin migración ni cambios de modelo.
 */
enum ReservationDurationUnit: string
{
    case Horas = 'hours';
    case DiasCorridos = 'calendar_days';
    case DiasHabiles = 'business_days';

    public function label(): string
    {
        return match ($this) {
            self::Horas => 'Horas',
            self::DiasCorridos => 'Días corridos',
            self::DiasHabiles => 'Días hábiles',
        };
    }

    /**
     * Calcula el vencimiento de una reserva a partir de un instante dado.
     *
     * Días hábiles considera de lunes a viernes. No descuenta feriados chilenos:
     * eso requiere un calendario de feriados y queda para una fase posterior.
     */
    public function vencimientoDesde(DateTimeInterface $desde, int $cantidad): CarbonImmutable
    {
        $base = CarbonImmutable::instance($desde);

        return match ($this) {
            self::Horas => $base->addHours($cantidad),
            self::DiasCorridos => $base->addDays($cantidad),
            self::DiasHabiles => $base->addWeekdays($cantidad),
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
