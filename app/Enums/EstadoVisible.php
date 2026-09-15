<?php

namespace App\Enums;

/**
 * Estado que se dibuja en pantalla con etiqueta y color (`App\Support\Presentar`).
 */
interface EstadoVisible
{
    public function label(): string;

    public function color(): string;
}
