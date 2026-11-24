<?php

namespace App\Support\Certificados;

use App\Support\Texto;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Reads the person's name printed on certificate PDFs made outside the system.
 *
 * Certificates of one event share every line but the name, so the name is the
 * line that does not repeat across the batch (and the event's earlier uploads).
 * The file name is only a fallback: in the first real batch "1_Indrid..." was
 * printed "Ingrid Marlene Itha..." (docs/decisiones/11-24-2026-certificados.md).
 */
class LectorDeCertificados
{
    public function texto(string $rutaAbsoluta): ?string
    {
        try {
            $texto = (new Parser)->parseFile($rutaAbsoluta)->getText();
        } catch (Throwable) {
            return null;
        }

        $texto = trim($texto);

        return $texto === '' ? null : mb_substr($texto, 0, 20000);
    }

    /**
     * @param  array<int|string, string|null>  $textos  texts of the batch, by key
     * @param  array<int, string|null>  $previos  texts of certificates already in the event
     * @return array<int|string, string|null> name found in each text, by the same key
     */
    public function nombres(array $textos, array $previos = []): array
    {
        $todos = array_values(array_filter([...array_values($textos), ...$previos], fn (?string $t): bool => $t !== null));
        $total = count($todos);

        // A line counts once per document; it is template when half or more share it.
        $frecuencia = [];

        foreach ($todos as $texto) {
            foreach (array_unique($this->lineas($texto)) as $linea) {
                $frecuencia[$linea] = ($frecuencia[$linea] ?? 0) + 1;
            }
        }

        $umbral = max(2, (int) ceil($total / 2));

        $nombres = [];

        foreach ($textos as $clave => $texto) {
            if ($texto === null || $total < 2) {
                $nombres[$clave] = null;

                continue;
            }

            $propias = array_values(array_filter(
                array_unique($this->lineas($texto)),
                fn (string $linea): bool => ($frecuencia[$linea] ?? 0) < $umbral && $this->pareceNombre($linea),
            ));

            usort($propias, fn (string $a, string $b): int => Texto::palabras($b) <=> Texto::palabras($a));

            $nombres[$clave] = $propias[0] ?? null;
        }

        return $nombres;
    }

    /** "12_Ricardo Patricio Durán.pdf" → "Ricardo Patricio Durán". */
    public function nombreDesdeArchivo(string $archivo): ?string
    {
        $base = pathinfo($archivo, PATHINFO_FILENAME);
        $base = preg_replace('/[_\-.]+/u', ' ', $base) ?? $base;
        $base = preg_replace('/^\s*\d+\s*/u', '', $base) ?? $base;
        $base = preg_replace('/\bcertificado(s)?\b/iu', '', $base) ?? $base;

        return Texto::limpiar($base);
    }

    /** @return array<int, string> */
    private function lineas(string $texto): array
    {
        return array_values(array_filter(array_map(
            fn (string $l): string => Texto::limpiar($l) ?? '',
            preg_split('/\R/u', $texto) ?: [],
        )));
    }

    private function pareceNombre(string $linea): bool
    {
        $palabras = Texto::palabras($linea);

        return mb_strlen($linea) <= 80
            && $palabras >= 2 && $palabras <= 8
            && preg_match("/^\p{L}[\p{L}'’.\- ]+$/u", $linea) === 1;
    }
}
