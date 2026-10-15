<?php

namespace App\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Publico\Concerns\ResuelveOrdenesDelPortal;
use App\Models\EventAttachment;
use App\Models\EventSession;
use App\Support\GeneradorQr;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Portal del cliente: las cuatro pestañas (Programa, Inscripción, Pago y
 * Credenciales) de una orden. Cada una es su propia ruta bajo el token, sin
 * JavaScript, y todas se pueden ver siempre: el cliente salta entre ellas.
 *
 * Un borrador todavía no tiene portal: se completa en los pasos del recorrido.
 */
class PortalController extends Controller
{
    use ResuelveOrdenesDelPortal;

    public function __construct(private readonly GeneradorQr $qr) {}

    public function programa(Request $request, string $token): RedirectResponse|View
    {
        $orden = $this->colegioElegido($this->resolverParaConsulta($token), $request);

        $evento = $orden->event;
        $jornadas = $evento->sessions;

        return view('publico.portal.programa', $this->datosDelPortal($token, $orden, 'programa') + [
            'jornadas' => $jornadas,
            'adjuntos' => $evento->attachments->filter(fn (EventAttachment $a): bool => $a->existe())->values(),
            'hayCalendario' => $jornadas->contains(fn (EventSession $j): bool => $j->starts_at !== null),
        ]);
    }

    public function inscripcion(Request $request, string $token): RedirectResponse|View
    {
        $orden = $this->colegioElegido($this->resolverParaConsulta($token), $request);

        return $this->vistaInscripcion($token, $orden);
    }

    public function pago(Request $request, string $token): RedirectResponse|View
    {
        $orden = $this->colegioElegido($this->resolverParaConsulta($token), $request);

        if ($orden->esBorrador()) {
            return $this->vistaResumen($token, $orden);
        }

        return view('publico.portal.pago', $this->datosDelPortal($token, $orden, 'pago'));
    }

    public function credenciales(Request $request, string $token): RedirectResponse|View
    {
        $orden = $this->colegioElegido($this->resolverParaConsulta($token), $request);

        // Sin pago aprobado no se arma ni el QR: lo que no sale del servidor
        // no se puede sacar del HTML.
        $tickets = $orden->payment_status->liberaCredenciales()
            ? $orden->tickets()->vigentes()->with('event', 'order', 'participant')->get()->keyBy('participant_id')
            : collect();

        return view('publico.portal.credenciales', $this->datosDelPortal($token, $orden, 'credenciales') + [
            'participantes' => $orden->participantesVigentes()->with(['accessType', 'establishment'])->get(),
            'tickets' => $tickets,
            'qr' => $this->qr,
        ]);
    }

    /** Documento adjunto del evento, servido desde el disco privado con el token. */
    public function adjunto(Request $request, string $token, EventAttachment $attachment): StreamedResponse
    {
        $orden = $this->resolverParaConsulta($token);

        // El adjunto debe ser del mismo evento que la orden del token.
        abort_unless($attachment->event_id === $orden->event_id && $attachment->existe(), 404);

        $disco = Storage::disk($attachment->disk);

        // ?ver=1 lo abre en el navegador ("Ver programa"); sin él, se descarga.
        return $request->boolean('ver')
            ? $disco->response($attachment->path, $attachment->original_name, ['Content-Disposition' => 'inline; filename="'.addslashes($attachment->original_name).'"'])
            : $disco->download($attachment->path, $attachment->original_name);
    }

    /** Calendario del evento (.ics): una entrada por jornada con hora. */
    public function calendario(Request $request, string $token): Response
    {
        $orden = $this->resolverParaConsulta($token);
        $evento = $orden->event;

        $lugarDelEvento = collect([$evento->location, $evento->city])->filter()->implode(', ');

        $lineas = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Vision Activa//Inscripciones//ES',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
        ];

        foreach ($evento->sessions->filter(fn (EventSession $j): bool => $j->starts_at !== null) as $jornada) {
            $lugar = $jornada->location ?: $lugarDelEvento;

            $lineas[] = 'BEGIN:VEVENT';
            $lineas[] = 'UID:jornada-'.$jornada->id.'-evento-'.$evento->id.'@inscripciones';
            $lineas[] = 'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z');
            // En UTC: es el mismo instante que la hora de Chile y no obliga a
            // escribir a mano el VTIMEZONE de un país con horario de verano.
            $lineas[] = 'DTSTART:'.$jornada->starts_at->copy()->utc()->format('Ymd\THis\Z');
            if ($jornada->ends_at) {
                $lineas[] = 'DTEND:'.$jornada->ends_at->copy()->utc()->format('Ymd\THis\Z');
            }
            $lineas[] = 'SUMMARY:'.$this->escaparIcs($evento->name.' · '.$jornada->name);
            if ($lugar !== '') {
                $lineas[] = 'LOCATION:'.$this->escaparIcs($lugar);
            }
            $lineas[] = 'END:VEVENT';
        }

        $lineas[] = 'END:VCALENDAR';

        return response(implode("\r\n", array_map($this->plegarIcs(...), $lineas))."\r\n", 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.(Str::slug($evento->name) ?: 'evento').'.ics"',
        ]);
    }

    private function escaparIcs(string $texto): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $texto);
    }

    /** RFC 5545: las líneas se parten a los 75 octetos, sin cortar un carácter. */
    private function plegarIcs(string $linea): string
    {
        if (strlen($linea) <= 75) {
            return $linea;
        }

        $partes = [];
        $actual = '';

        foreach (mb_str_split($linea) as $caracter) {
            if (strlen($actual) + strlen($caracter) > ($partes === [] ? 75 : 74)) {
                $partes[] = $actual;
                $actual = '';
            }
            $actual .= $caracter;
        }

        $partes[] = $actual;

        return implode("\r\n ", $partes);
    }
}
