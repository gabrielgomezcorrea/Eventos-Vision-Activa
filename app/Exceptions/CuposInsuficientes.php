<?php

namespace App\Exceptions;

use App\Models\EventSession;
use RuntimeException;

class CuposInsuficientes extends RuntimeException
{
    public function __construct(
        public readonly ?EventSession $jornada = null,
        public readonly int $solicitados = 0,
        string $message = '',
    ) {
        parent::__construct($message ?: 'No hay cupos disponibles.');
    }

    public static function para(?EventSession $jornada, int $solicitados): self
    {
        $nombre = $jornada->name ?? 'la jornada seleccionada';
        $disponibles = $jornada?->cuposDisponibles();

        $mensaje = $disponibles === 0
            ? "No quedan cupos disponibles para {$nombre}."
            : "No hay cupos suficientes para {$nombre}. Solicitados: {$solicitados}, disponibles: {$disponibles}.";

        return new self($jornada, $solicitados, $mensaje);
    }
}
