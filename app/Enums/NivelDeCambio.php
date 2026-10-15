<?php

namespace App\Enums;

/**
 * Cuánto puede cambiar el propio cliente de una inscripción ya confirmada.
 *
 * Completo: participantes, accesos y entidad pagadora (todavía no hay plata de
 * por medio). SoloDatos: corregir nombres y contacto, sin tocar monto ni cupos.
 */
enum NivelDeCambio: string
{
    case Completo = 'completo';
    case SoloDatos = 'solo_datos';
    case Ninguno = 'ninguno';

    public function permiteCorregirDatos(): bool
    {
        return $this !== self::Ninguno;
    }

    public function permiteCambiarLaInscripcion(): bool
    {
        return $this === self::Completo;
    }
}
