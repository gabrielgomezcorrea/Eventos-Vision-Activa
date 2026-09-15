<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Vigencia del enlace
    |--------------------------------------------------------------------------
    |
    | Cuánto dura un enlace de acceso desde que se emite. El cliente nunca queda
    | bloqueado por un enlace vencido: puede pedir uno nuevo con su correo.
    |
    */

    'ttl_minutes' => (int) env('MAGIC_LINK_TTL_MINUTES', 60 * 24 * 7),

    /*
    |--------------------------------------------------------------------------
    | Límite de envíos
    |--------------------------------------------------------------------------
    |
    | Cuántos enlaces se pueden pedir por correo y por IP en la ventana dada.
    | Evita que el formulario se use para enviar correo no deseado a terceros.
    |
    */

    'max_per_window' => (int) env('MAGIC_LINK_MAX_PER_WINDOW', 5),
    'window_minutes' => (int) env('MAGIC_LINK_WINDOW_MINUTES', 15),

];
