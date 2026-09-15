<?php

namespace App\Enums;

/**
 * Estado de publicación de un evento.
 */
enum EventStatus: string implements EstadoVisible
{
    case Borrador = 'draft';
    case Publicado = 'published';
    case Cerrado = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Publicado => 'Publicado',
            self::Cerrado => 'Cerrado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Borrador => 'gray',
            self::Publicado => 'success',
            self::Cerrado => 'warning',
        };
    }

    /** El evento admite nuevas inscripciones. */
    public function admiteInscripciones(): bool
    {
        return $this === self::Publicado;
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

    public function getColor(): string
    {
        return $this->color();
    }
}
