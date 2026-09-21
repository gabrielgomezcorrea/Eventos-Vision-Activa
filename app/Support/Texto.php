<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Normaliza lo que la gente escribe en los formularios públicos.
 *
 * Nadie llena un formulario pensando en cómo se va a ver después en una
 * planilla: escriben "JUAN carlos", "liceo a-12" o "  Pérez  ". Corregirlo por
 * detrás evita que la misma institución aparezca escrita de tres formas y que
 * un correo empiece con "Hola JUAN CARLOS,".
 */
class Texto
{
    /**
     * Partículas que van en minúscula dentro de un nombre.
     *
     * "Juan de la Cruz", no "Juan De La Cruz". Nunca la primera palabra: hay
     * apellidos que empiezan por "De" y ahí sí lleva mayúscula.
     */
    private const PARTICULAS = ['de', 'del', 'la', 'las', 'los', 'y', 'da', 'do', 'dos', 'van', 'von', 'di', 'el'];

    /** Espacios repetidos colapsados y sin espacios en los extremos. */
    public static function limpiar(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $limpio = trim(preg_replace('/\s+/u', ' ', $valor) ?? $valor);

        return $limpio === '' ? null : $limpio;
    }

    /**
     * Cada palabra con su primera letra en mayúscula y el resto en minúscula,
     * respetando las partículas y los apellidos compuestos.
     *
     * "o'higgins" queda "O'Higgins" y "mac-iver" queda "Mac-Iver": cortar solo
     * por espacios dejaría "O'higgins".
     */
    public static function capitalizar(?string $valor): ?string
    {
        $limpio = self::limpiar($valor);

        if ($limpio === null) {
            return null;
        }

        $palabras = explode(' ', mb_strtolower($limpio));

        foreach ($palabras as $i => $palabra) {
            if ($i > 0 && in_array($palabra, self::PARTICULAS, true)) {
                continue;
            }

            $palabras[$i] = preg_replace_callback(
                "/(^|['\-])(\p{L})/u",
                fn (array $m): string => $m[1].mb_strtoupper($m[2]),
                $palabra,
            ) ?? $palabra;
        }

        return implode(' ', $palabras);
    }

    /**
     * Cantidad de palabras que cuentan como nombre o apellido.
     *
     * Las partículas no suman: "de la Cruz" es un apellido, no tres, y un tope
     * que las contara rechazaría a media guía de teléfonos.
     */
    public static function palabras(?string $valor): int
    {
        $limpio = self::limpiar($valor);

        if ($limpio === null) {
            return 0;
        }

        return count(array_filter(
            explode(' ', mb_strtolower($limpio)),
            fn (string $palabra): bool => ! in_array($palabra, self::PARTICULAS, true),
        ));
    }

    /**
     * Teléfono móvil chileno en formato único: 56912345678, solo dígitos.
     *
     * Se acepta escrito como sea —con espacios, guiones, paréntesis, con o sin
     * el 56 o el +— y siempre se guarda igual. Sin esto la misma persona queda
     * con dos teléfonos distintos según cómo lo tipeó esa vez. Sin "+" porque
     * Excel lo lee como fórmula y porque así sirve directo para wa.me.
     */
    public static function telefono(?string $valor): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $valor) ?? '';

        $digitos = match (true) {
            Str::startsWith($digitos, '56') => Str::after($digitos, '56'),
            Str::startsWith($digitos, '09') => Str::after($digitos, '0'),
            default => $digitos,
        };

        return strlen($digitos) === 9 && Str::startsWith($digitos, '9')
            ? '56'.$digitos
            : null;
    }

    /**
     * Comuna en su forma oficial si coincide con la lista, sin importar tildes
     * ni mayúsculas ("nunoa" → "Ñuñoa"). Lo que no está en la lista se acepta
     * limpio y capitalizado: nunca se bloquea a la persona por una comuna.
     */
    public static function comuna(?string $valor): ?string
    {
        $limpio = self::limpiar($valor);

        if ($limpio === null) {
            return null;
        }

        $clave = Str::lower(Str::ascii($limpio));

        foreach (self::comunas() as $comuna) {
            if (Str::lower(Str::ascii($comuna)) === $clave) {
                return $comuna;
            }
        }

        return self::capitalizar($limpio);
    }

    /** @return array<int, string> */
    public static function comunas(): array
    {
        $comunas = array_merge(...array_values(config('chile')));
        sort($comunas);

        return $comunas;
    }

    /** Correo en minúsculas y sin espacios: es una sola dirección, no dos. */
    public static function correo(?string $valor): ?string
    {
        $limpio = self::limpiar($valor);

        return $limpio === null ? null : mb_strtolower($limpio);
    }
}
