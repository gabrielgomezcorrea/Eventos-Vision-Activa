<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controla qué sitios pueden embeber el formulario público en un iframe.
 *
 * Los orígenes autorizados salen de EMBED_ALLOWED_ORIGINS. Si la lista está
 * vacía, no se permite embeber desde ningún sitio: es el default seguro.
 */
class PermitirEmbebido
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $origenes = $this->origenesAutorizados();

        // frame-ancestors 'none' bloquea el iframe. X-Frame-Options se elimina
        // porque no admite listas de orígenes y anularía la política anterior.
        $valor = $origenes === []
            ? "frame-ancestors 'none'"
            : "frame-ancestors 'self' ".implode(' ', $origenes);

        $response->headers->set('Content-Security-Policy', $valor);
        $response->headers->remove('X-Frame-Options');

        return $response;
    }

    /** @return array<int, string> */
    private function origenesAutorizados(): array
    {
        return collect(explode(',', (string) config('embed.allowed_origins')))
            ->map(fn (string $o) => trim($o))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
