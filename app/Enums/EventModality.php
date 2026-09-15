<?php

namespace App\Enums;

/**
 * Modalidad del evento. Un evento online no requiere lugar físico ni pulseras.
 */
enum EventModality: string
{
    case Presencial = 'presencial';
    case Online = 'online';
    case Mixta = 'mixta';

    public function label(): string
    {
        return match ($this) {
            self::Presencial => 'Presencial',
            self::Online => 'Online',
            self::Mixta => 'Mixta',
        };
    }

    /** Solo los eventos con presencia física usan acreditación y pulseras. */
    public function requiereAcreditacionPresencial(): bool
    {
        return $this !== self::Online;
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
