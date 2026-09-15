<?php

namespace App\Http\Controllers\Eventos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Eventos\FormularioPublicoRequest;
use App\Http\Requests\Eventos\ProgramaRequest;
use App\Models\Event;
use App\Models\EventAttachment;
use App\Support\Forms\ProgramFormField;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El formulario público de un evento, en su propia pantalla.
 *
 * Es un trabajo distinto: se entra a armarlo, se prueba, se corrige y se vuelve
 * otro día a seguir.
 */
class FormularioPublicoController extends Controller
{
    public function edit(Event $event): Response
    {
        Gate::authorize('update', $event);

        $event->load('attachments');

        return Inertia::render('eventos/formulario', [
            'evento' => [
                'id' => $event->id,
                'name' => $event->name,
                'admite_inscripciones' => $event->admiteInscripciones(),
                // Cargado, no como placeholder: un valor por defecto se muestra
                // puesto para quitarlo o cambiarlo, no se insinúa en gris.
                'consent_text' => $event->consent_text ?: Event::CONSENTIMIENTO_POR_DEFECTO,
                'program_email_intro' => $event->program_email_intro,
                'adjuntos' => $event->attachments->map(fn (EventAttachment $a): array => [
                    'id' => $a->id,
                    'nombre' => $a->original_name,
                    'peso' => $a->tamanoLegible(),
                ])->values()->all(),
                'max_adjuntos' => EventAttachment::MAXIMO,
                'max_mb' => EventAttachment::MAX_MB,
            ],
            // Si el evento nunca tocó sus campos se muestran los base ya
            // cargados, para quitar o agregar, en vez de una lista vacía.
            'campos' => $event->program_form_fields ?: ProgramFormField::porDefectoComoArray(),
            'tipos' => ProgramFormField::TIPOS,
            'embeber' => [
                'enlace' => route('publico.programa', ['event' => $event->slug]),
                'iframe' => route('publico.programa.embed', ['event' => $event->slug]),
                'origenes' => config('embed.allowed_origins') ?: null,
            ],
        ]);
    }

    public function update(FormularioPublicoRequest $request, Event $event): RedirectResponse
    {
        $claves = [];

        $campos = collect(Arr::wrap($request->validated('campos')))
            ->map(function (array $campo) use (&$claves): array {
                // El identificador no se pide: se calcula de la etiqueta la
                // primera vez y no se vuelve a tocar, para no romper dónde se
                // guarda el dato de los campos base.
                $clave = $campo['key'] ?: (Str::slug($campo['label'], '_') ?: 'pregunta');
                $base = $clave;
                $numero = 2;

                while (in_array($clave, $claves, true)) {
                    $clave = $base.'_'.$numero++;
                }

                $claves[] = $clave;
                $esLista = $campo['type'] === 'select';

                return (new ProgramFormField(
                    key: $clave,
                    label: $campo['label'],
                    type: $campo['type'],
                    enabled: true,
                    required: $clave === ProgramFormField::OBLIGATORIO_SIEMPRE || (bool) ($campo['required'] ?? false),
                    placeholder: $esLista ? null : ($campo['placeholder'] ?? null),
                    options: $esLista ? array_values($campo['options'] ?? []) : [],
                ))->toArray();
            })
            ->all();

        $event->update([
            'program_form_fields' => $campos,
            'consent_text' => $request->validated('consent_text'),
            'program_email_intro' => $request->validated('program_email_intro'),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Formulario guardado. Los cambios ya se ven en la página pública.']);

        return back();
    }

    public function subirPrograma(ProgramaRequest $request, Event $event): RedirectResponse
    {
        $disco = config('filesystems.private_disk');
        $archivo = $request->file('programa');

        $event->attachments()->create([
            'disk' => $disco,
            'path' => $archivo->store('programas/'.$event->getKey(), $disco),
            // El nombre original, para que el adjunto no llegue con el hash.
            'original_name' => $archivo->getClientOriginalName(),
            'mime_type' => $archivo->getClientMimeType(),
            'size' => $archivo->getSize(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Archivo cargado. Se adjunta al correo con el programa.']);

        return back();
    }

    public function quitarPrograma(Event $event, EventAttachment $attachment): RedirectResponse
    {
        Gate::authorize('update', $event);

        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Archivo quitado.']);

        return back();
    }
}
