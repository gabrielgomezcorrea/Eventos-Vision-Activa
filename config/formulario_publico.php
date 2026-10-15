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
    | Mide ráfagas, no cantidad: más de 20 envíos con el mismo correo en un
    | minuto (alguien enviando sin parar, no una persona). Quien lo pide cada tanto, o varias veces en una hora, no se topa
    | con nada. Si salta, la persona ve un aviso: nunca una falsa confirmación.
    |
    */

    'max_por_correo' => (int) env('PROGRAMA_MAX_POR_CORREO', 20),
    'ventana_segundos' => (int) env('PROGRAMA_VENTANA_SEGUNDOS', 60),

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
    | Bajo no sirve de nada y alto castiga a quien escribe rápido: 2 segundos.
    | Si salta, la persona ve un aviso y puede volver a enviar: el formulario
    | conserva lo escrito.
    |
    */

    'segundos_minimos' => (int) env('PROGRAMA_SEGUNDOS_MINIMOS', 1),

];
