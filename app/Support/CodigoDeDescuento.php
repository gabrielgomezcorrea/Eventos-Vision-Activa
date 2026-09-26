<?php

namespace App\Support;

use App\Models\Event;
use App\Models\Ticket;
use Closure;
use Illuminate\Support\Str;

/**
 * Códigos de descuento: cómo se generan, se escriben y se aceptan.
 *
 * La seguridad no está en que el código sea raro sino en que haya muchas
 * combinaciones, en el límite de intentos y en el tope de usos. Exigir
 * símbolos solo perjudica a quien lo dicta por teléfono.
 */
class CodigoDeDescuento
{
    public const LARGO_GENERADO = 8;

    public const LARGO_MINIMO = 8;

    public const LARGO_MAXIMO = 20;

    /** Palabras que cualquiera probaría primero. Se buscan dentro del código. */
    public const PALABRAS_OBVIAS = [
        'FREE', 'GRATIS', 'GRATUITO', 'DESCUENTO', 'PROMO', 'INVITADO', 'INVITACION',
        'CORTESIA', 'OFERTA', 'CUPON', 'CODIGO', 'PRUEBA', 'TEST', 'ADMIN', 'PASSWORD',
    ];

    /** Mayúsculas, sin espacios, guiones ni tildes: la única forma que se guarda y se compara. */
    public static function normalizar(?string $valor): string
    {
        return strtoupper(preg_replace('/[\s\-_.]+/', '', Str::ascii((string) $valor)) ?? '');
    }

    /** XXXX-XXXX para leerlo y dictarlo. */
    public static function formatear(string $codigo): string
    {
        return implode('-', str_split($codigo, 4));
    }

    /** Aleatorio, del alfabeto sin caracteres ambiguos y con al menos una letra y un número. */
    public static function generar(): string
    {
        $alfabeto = str_split(Ticket::ALFABETO);

        do {
            $codigo = '';

            for ($i = 0; $i < self::LARGO_GENERADO; $i++) {
                $codigo .= $alfabeto[random_int(0, count($alfabeto) - 1)];
            }
        } while (! self::tieneLetraYNumero($codigo));

        return $codigo;
    }

    private static function tieneLetraYNumero(string $codigo): bool
    {
        return preg_match('/[A-Z]/', $codigo) === 1 && preg_match('/\d/', $codigo) === 1;
    }

    /**
     * Regla para un código escrito a mano (el generado la cumple siempre). Se
     * aplica sobre el valor ya normalizado.
     *
     * @return array<int, mixed>
     */
    public static function regla(Event $evento): array
    {
        return [
            'required',
            'string',
            function (string $atributo, mixed $valor, Closure $falla) use ($evento): void {
                $codigo = (string) $valor;

                if (! preg_match('/^[A-Z0-9]{'.self::LARGO_MINIMO.','.self::LARGO_MAXIMO.'}$/', $codigo)) {
                    $falla('Usa entre 8 y 20 letras y números, sin símbolos. Ejemplo: K7QX-M4PR.');

                    return;
                }

                if (! self::tieneLetraYNumero($codigo)) {
                    $falla('Mezcla letras y números. Ejemplo: K7QX-M4PR.');

                    return;
                }

                $prohibidas = [...self::PALABRAS_OBVIAS];
                $identificador = self::normalizar($evento->slug);

                if (strlen($identificador) >= 4) {
                    $prohibidas[] = $identificador;
                }

                foreach ($prohibidas as $palabra) {
                    if (str_contains($codigo, $palabra)) {
                        $falla('Ese código es fácil de adivinar. Usa el botón Generar o elige uno sin palabras comunes.');

                        return;
                    }
                }
            },
        ];
    }
}
