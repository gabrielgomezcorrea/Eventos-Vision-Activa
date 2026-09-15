<?php

namespace App\Enums;

enum ParticipantStatus: string implements EstadoVisible
{
    case Registrado = 'registered';
    case CredencialBloqueada = 'credential_blocked';
    case CredencialEmitida = 'credential_issued';
    case Acreditado = 'accredited';
    case Reemplazado = 'replaced';

    public function label(): string
    {
        return match ($this) {
            self::Registrado => 'Registrado',
            self::CredencialBloqueada => 'Credencial bloqueada',
            self::CredencialEmitida => 'Credencial emitida',
            self::Acreditado => 'Acreditado',
            self::Reemplazado => 'Reemplazado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Registrado => 'gray',
            self::CredencialBloqueada => 'warning',
            self::CredencialEmitida => 'info',
            self::Acreditado => 'success',
            self::Reemplazado => 'danger',
        };
    }

    /** Un participante reemplazado ya no ocupa cupo ni recibe credencial. */
    public function estaVigente(): bool
    {
        return $this !== self::Reemplazado;
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
