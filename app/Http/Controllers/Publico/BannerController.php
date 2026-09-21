<?php

namespace App\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Entrega el banner del evento.
 *
 * Vive en las rutas públicas porque un cliente de correo la descarga sin
 * sesión. No expone nada privado: es la imagen que el propio evento publica.
 */
class BannerController extends Controller
{
    public function __invoke(Event $event): Response
    {
        abort_unless($event->tieneBanner(), 404);

        // `response()` del disco y no `response()->file()`: sirve igual si un
        // día el disco privado deja de ser local.
        return Storage::disk($event->banner_disk)->response($event->banner_path, null, [
            'Content-Type' => $event->banner_mime,
            // La URL lleva la marca de tiempo del banner, así que lo que
            // responde esta URL nunca cambia.
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
