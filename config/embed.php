<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Orígenes autorizados para embeber el formulario público
    |--------------------------------------------------------------------------
    |
    | Lista separada por comas de los sitios que pueden mostrar el formulario
    | dentro de un iframe, por ejemplo:
    |
    |   EMBED_ALLOWED_ORIGINS=https://www.liderazgoescolar.cl,https://otro.cl
    |
    | Vacío significa que nadie puede embeberlo. El formulario sigue accesible
    | por su enlace directo.
    |
    */

    'allowed_origins' => env('EMBED_ALLOWED_ORIGINS', ''),

];
