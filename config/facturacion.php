<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Copia a administración
    |--------------------------------------------------------------------------
    |
    | Cada factura que se envía al cliente lleva copia a esta dirección, para
    | que administración tenga constancia del envío sin tener que pedirla.
    |
    | Vacío: no se manda copia y el envío al cliente ocurre igual. Nunca se
    | escribe la dirección en el código.
    |
    */

    'copia_administracion' => env('FACTURACION_COPIA_ADMINISTRACION'),

];
