<?php

namespace App\Support;

/**
 * RUT chileno.
 *
 * El RUT es opcional en todo el sistema: en inscripciones institucionales muchas
 * veces no viene, y exigirlo bloquearía el proceso. Pero cuando viene informado
 * se valida y se normaliza, para que la búsqueda en acreditación funcione sin
 * depender de cómo lo escribió cada persona.
 */
class Rut
{
    /** Normaliza a la forma 12345678-9, o null si viene vacío. */
    public static function normalizar(?string $rut): ?string
    {
        $limpio = self::limpiar($rut);

        if ($limpio === null) {
            return null;
        }

        $cuerpo = substr($limpio, 0, -1);
        $dv = substr($limpio, -1);

        return $cuerpo.'-'.$dv;
    }

    public static function esValido(?string $rut): bool
    {
        $limpio = self::limpiar($rut);

        if ($limpio === null) {
            return false;
        }

        $cuerpo = substr($limpio, 0, -1);
        $dv = substr($limpio, -1);

        if (! ctype_digit($cuerpo) || strlen($cuerpo) < 7) {
            return false;
        }

        return $dv === self::digitoVerificador($cuerpo);
    }

    /** Dígito verificador por módulo 11. */
    public static function digitoVerificador(string $cuerpo): string
    {
        $suma = 0;
        $multiplicador = 2;

        foreach (array_reverse(str_split($cuerpo)) as $digito) {
            $suma += (int) $digito * $multiplicador;
            $multiplicador = $multiplicador === 7 ? 2 : $multiplicador + 1;
        }

        $resto = 11 - ($suma % 11);

        return match ($resto) {
            11 => '0',
            10 => 'K',
            default => (string) $resto,
        };
    }

    /** Quita puntos, guiones y espacios. Devuelve null si queda vacío. */
    private static function limpiar(?string $rut): ?string
    {
        if ($rut === null) {
            return null;
        }

        $limpio = strtoupper(preg_replace('/[^0-9kK]/', '', $rut) ?? '');

        return $limpio === '' ? null : $limpio;
    }
}
