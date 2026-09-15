<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Límite de solicitudes por correo
    |--------------------------------------------------------------------------
    |
    | El formulario le envía el programa a la dirección que se escriba, así que
    | sin límite sirve para bombardear el correo de un tercero y de paso quema
    | la cuota de envío. El tope por IP vive en la ruta; este es por dirección.
    |
    | Es generoso a propósito: una persona que pide el programa dos o tres veces
    | en una hora no debe encontrarse con una puerta cerrada.
    |
    */

    'max_por_correo' => (int) env('PROGRAMA_MAX_POR_CORREO', 3),
    'ventana_minutos' => (int) env('PROGRAMA_VENTANA_MINUTOS', 60),

    /*
    |--------------------------------------------------------------------------
    | Tiempo mínimo de llenado
    |--------------------------------------------------------------------------
    |
    | Un bot completa y envía en milisegundos; una persona no baja de unos pocos
    | segundos ni copiando desde un papel. El instante en que se dibujó el
    | formulario viaja firmado en un campo oculto, porque estas rutas no tienen
    | sesión donde guardarlo.
    |
    | Bajo no sirve de nada y alto castiga a quien escribe rápido: 3 segundos es
    | el punto donde no hay falsos positivos.
    |
    */

    'segundos_minimos' => (int) env('PROGRAMA_SEGUNDOS_MINIMOS', 3),

];
