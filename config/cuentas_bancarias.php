<?php

/**
 * Opciones para cargar una cuenta bancaria. Se eligen de una lista para que
 * el mismo banco no quede escrito de tres formas distintas.
 *
 * Bancos: los que opera la gente en Chile, del más usado al menos usado.
 * Para agregar uno, basta sumarlo aquí.
 */
return [

    'bancos' => [
        'BancoEstado',
        'Banco de Chile',
        'Santander',
        'BCI',
        'Scotiabank',
        'Itaú',
        'Banco Security',
        'Banco BICE',
        'Banco Falabella',
        'Banco Ripley',
        'Banco Consorcio',
        'Banco Internacional',
        'BTG Pactual',
        'HSBC',
        'Coopeuch',
    ],

    'tipos_de_cuenta' => [
        'Cuenta Corriente',
        'Cuenta Vista',
        'CuentaRUT',
        'Cuenta de Ahorro',
    ],

];
