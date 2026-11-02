<?php

namespace App\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Logo del evento: público porque lo pide el correo o el navegador sin sesión. */
class EventLogoController extends Controller
{
    public function __invoke(Event $event): Response
    {
        abort_unless($event->tieneLogo(), 404);

        return Storage::disk($event->logo_disk)->response($event->logo_path, null, [
            'Content-Type' => $event->logo_mime,
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
