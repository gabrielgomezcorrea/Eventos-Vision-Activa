<?php

namespace App\Support\Acreditacion;

use App\Enums\ResultadoAcreditacion;
use App\Models\Accreditation;
use App\Models\Ticket;

/**
 * Lo que devuelve resolver una credencial: el desenlace y, si existe, el
 * ticket y su acreditación previa.
 */
class Resultado
{
    public function __construct(
        public readonly ResultadoAcreditacion $tipo,
        public readonly ?Ticket $ticket = null,
        public readonly ?Accreditation $acreditacion = null,
    ) {}

    public static function valida(Ticket $ticket): self
    {
        return new self(ResultadoAcreditacion::Valida, $ticket);
    }

    public static function yaAcreditado(Ticket $ticket, Accreditation $acreditacion): self
    {
        return new self(ResultadoAcreditacion::YaAcreditado, $ticket, $acreditacion);
    }

    public static function anulada(Ticket $ticket): self
    {
        return new self(ResultadoAcreditacion::Anulada, $ticket);
    }

    public static function pagoNoAprobado(Ticket $ticket): self
    {
        return new self(ResultadoAcreditacion::PagoNoAprobado, $ticket);
    }

    public static function otroEvento(Ticket $ticket): self
    {
        return new self(ResultadoAcreditacion::OtroEvento, $ticket);
    }

    public static function noEncontrada(): self
    {
        return new self(ResultadoAcreditacion::NoEncontrada);
    }

    public function permiteAcreditar(): bool
    {
        return $this->tipo->permiteAcreditar();
    }
}
