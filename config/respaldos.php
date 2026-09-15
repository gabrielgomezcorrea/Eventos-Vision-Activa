<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Carpeta de respaldos
    |--------------------------------------------------------------------------
    |
    | Cada respaldo es un .tar.gz con la base de datos y los archivos privados
    | (comprobantes, facturas y programas). Queda en el mismo servidor: la copia
    | fuera del VPS se configura aparte, porque un respaldo que se pierde junto
    | con el servidor no sirve.
    |
    */

    'carpeta' => env('RESPALDOS_CARPETA', storage_path('app/respaldos')),

    /*
    |--------------------------------------------------------------------------
    | Cuántos conservar
    |--------------------------------------------------------------------------
    |
    | Se corre uno al día; al crear uno nuevo se borran los más antiguos por
    | sobre esta cantidad.
    |
    */

    'conservar' => (int) env('RESPALDOS_CONSERVAR', 14),

];
