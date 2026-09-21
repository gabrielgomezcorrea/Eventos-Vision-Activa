<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Sites allowed to show the public form inside an iframe.
 *
 * Managed by Administración from the panel instead of the server .env: editing
 * the server for every new landing was slow. Stored as bare domains; each one
 * also covers its subdomains (www, publicidad...).
 */
class WebsAutorizadas
{
    public const CLAVE = 'webs_autorizadas';

    /** @return array<int, string> */
    public static function lista(): array
    {
        $guardado = json_decode((string) Setting::valor(self::CLAVE), true);

        return is_array($guardado) ? array_values($guardado) : [];
    }

    /** @param  array<int, string>  $dominios */
    public static function guardar(array $dominios): void
    {
        $dominios = array_values(array_unique($dominios));
        sort($dominios);

        Setting::guardar([self::CLAVE => $dominios === [] ? null : json_encode($dominios)]);
    }

    /**
     * Bare domain from whatever was pasted: "https://www.Colegio.cl/inscripcion"
     * → "colegio.cl". Null when it is not a domain.
     */
    public static function normalizar(?string $escrito): ?string
    {
        $valor = mb_strtolower(trim((string) $escrito));
        $valor = (string) preg_replace('#^[a-z]+://#', '', $valor);
        $valor = (string) preg_replace('#[/?\#:].*$#', '', $valor);
        $valor = (string) preg_replace('#^www\.#', '', $valor);
        $valor = rtrim($valor, '.');

        // Domains with ñ or accents are stored in their ASCII form, as browsers send them.
        if (preg_match('/[^\x00-\x7F]/', $valor)) {
            if (! preg_match('/^[\pL\pN.-]+$/u', $valor) || ! function_exists('idn_to_ascii')) {
                return null;
            }

            $valor = (string) idn_to_ascii($valor, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        }

        if (strlen($valor) > 253) {
            return null;
        }

        return preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+([a-z]{2,63}|xn--[a-z0-9-]{2,59})$/', $valor) ? $valor : null;
    }

    /** @return array<int, string> sources for frame-ancestors */
    public static function origenes(): array
    {
        return collect(self::lista())
            ->flatMap(fn (string $dominio): array => ["https://{$dominio}", "https://*.{$dominio}"])
            ->all();
    }
}
