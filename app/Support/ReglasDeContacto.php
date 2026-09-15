<?php

namespace App\Support;

use App\Support\Forms\ProgramFormField;
use Closure;

/**
 * Validation and normalization shared by every door where a contact enters:
 * public form, enrollment and panel.
 *
 * It stops junk ("C4rress", ".", two emails in one field) and nothing more.
 * A real person with an unusual name must always get through: an older client
 * blocked by a strict rule leaves instead of calling.
 */
class ReglasDeContacto
{
    private const LETRAS = '/^[\pL\s\x27-]+$/u';

    /**
     * Trims, collapses spaces and line breaks, strips HTML. A value left with
     * only signs (".", "-") becomes null, so it fails "required".
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public static function limpiar(array $datos): array
    {
        return array_map(function (mixed $valor): mixed {
            if (! is_string($valor)) {
                return $valor;
            }

            $limpio = Texto::limpiar(strip_tags($valor));

            return $limpio !== null && preg_match('/[\pL\pN]/u', $limpio) ? $limpio : null;
        }, $datos);
    }

    /** @return array<int, mixed> */
    public static function nombres(bool $obligatorio = true): array
    {
        return self::reglasDeNombre($obligatorio, 'María José', 3, 'Escribe solo los nombres. Los apellidos van en el campo siguiente.');
    }

    /** @return array<int, mixed> */
    public static function apellidos(bool $obligatorio = true): array
    {
        return self::reglasDeNombre($obligatorio, 'Pérez Soto', 2, 'Escribe solo los apellidos, sin los nombres.');
    }

    /**
     * Responsible's name is one field: it needs at least a name and a surname.
     *
     * @return array<int, mixed>
     */
    public static function nombreCompleto(bool $obligatorio = true): array
    {
        return [
            ...self::reglasDeNombre($obligatorio, 'María Pérez Soto'),
            function (string $atributo, mixed $valor, Closure $falla): void {
                if (Texto::palabras((string) $valor) < 2) {
                    $falla('Escribe nombre y apellido, como María Pérez Soto.');
                }
            },
        ];
    }

    /**
     * @param  array<int, string>  $opciones  the public form can edit its list per event
     * @return array<int, mixed>
     */
    public static function cargo(bool $obligatorio = true, array $opciones = ProgramFormField::CARGOS): array
    {
        return [
            $obligatorio ? 'required' : 'nullable',
            'string',
            function (string $atributo, mixed $valor, Closure $falla) use ($opciones): void {
                if (! in_array($valor, $opciones, true)) {
                    $falla('Elige un cargo de la lista. Si no está, elige "Otro" y escríbelo.');
                }
            },
        ];
    }

    /** @return array<int, mixed> */
    public static function cargoOtro(string $campoCargo): array
    {
        return [
            'required_if:'.$campoCargo.','.ProgramFormField::CARGO_OTRO,
            'nullable',
            'string',
            'max:120',
            function (string $atributo, mixed $valor, Closure $falla): void {
                if (! preg_match(self::LETRAS, (string) $valor) || preg_match_all('/\pL/u', (string) $valor) < 3) {
                    $falla('Escribe el cargo solo con letras, como Jefe de UTP.');
                }
            },
        ];
    }

    /** @return array<int, mixed> */
    public static function correo(bool $obligatorio = true): array
    {
        return [
            $obligatorio ? 'required' : 'nullable',
            'string',
            'max:255',
            function (string $atributo, mixed $valor, Closure $falla): void {
                if (filter_var(str_replace(' ', '', (string) $valor), FILTER_VALIDATE_EMAIL) === false) {
                    $falla('Escribe un solo correo, como nombre@colegio.cl.');
                }
            },
        ];
    }

    /** @return array<int, mixed> */
    public static function establecimiento(bool $obligatorio = true): array
    {
        return [
            $obligatorio ? 'required' : 'nullable',
            'string',
            'max:255',
            function (string $atributo, mixed $valor, Closure $falla): void {
                if (preg_match_all('/\pL/u', (string) $valor) < 3) {
                    $falla('Escribe el nombre del establecimiento, como Liceo Bicentenario.');
                }
            },
        ];
    }

    /**
     * Leaves each value as it must be stored.
     *
     * @param  array<string, mixed>  $datos
     * @param  array<string, 'nombre'|'correo'|'cargo'|'texto'>  $tipos
     * @return array<string, mixed>
     */
    public static function normalizar(array $datos, array $tipos): array
    {
        foreach ($tipos as $clave => $tipo) {
            if (! array_key_exists($clave, $datos)) {
                continue;
            }

            $valor = is_string($datos[$clave]) ? $datos[$clave] : null;

            $datos[$clave] = match ($tipo) {
                'nombre' => Texto::capitalizar($valor),
                'correo' => $valor === null ? null : mb_strtolower(str_replace(' ', '', $valor)),
                'cargo' => $valor === ProgramFormField::CARGO_OTRO
                    ? Texto::capitalizar(is_string($datos[$clave.'_otro'] ?? null) ? $datos[$clave.'_otro'] : null)
                    : $valor,
                'texto' => Texto::limpiar($valor),
            };

            if ($tipo === 'cargo') {
                unset($datos[$clave.'_otro']);
            }
        }

        return $datos;
    }

    /**
     * Splits a stored position back into list value and "Otro" text, to
     * prefill an edit form.
     *
     * @return array<string, string|null>
     */
    public static function separarCargo(?string $guardado, string $campo = 'position'): array
    {
        if ($guardado === null || in_array($guardado, ProgramFormField::CARGOS, true)) {
            return [$campo => $guardado];
        }

        return [$campo => ProgramFormField::CARGO_OTRO, $campo.'_otro' => $guardado];
    }

    /** @return array<int, mixed> */
    private static function reglasDeNombre(bool $obligatorio, string $ejemplo, ?int $maximoDePalabras = null, string $mensajeDePalabras = ''): array
    {
        return [
            $obligatorio ? 'required' : 'nullable',
            'string',
            'max:255',
            function (string $atributo, mixed $valor, Closure $falla) use ($ejemplo, $maximoDePalabras, $mensajeDePalabras): void {
                $valor = (string) $valor;

                if (! preg_match(self::LETRAS, $valor)) {
                    $falla("Escribe solo letras, como {$ejemplo}.");
                } elseif (preg_match_all('/\pL/u', $valor) < 2) {
                    $falla("Escribe el nombre completo, como {$ejemplo}.");
                } elseif (preg_match('/(\pL)\1\1/iu', $valor)) {
                    $falla('Revisa lo escrito: hay una letra repetida tres veces seguidas.');
                } elseif ($maximoDePalabras !== null && Texto::palabras($valor) > $maximoDePalabras) {
                    $falla($mensajeDePalabras);
                }
            },
        ];
    }
}
