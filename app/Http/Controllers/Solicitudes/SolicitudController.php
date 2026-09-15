<?php

namespace App\Http\Controllers\Solicitudes;

use App\Actions\ExportarContactos;
use App\Enums\PaymentStatus;
use App\Enums\Permiso;
use App\Http\Controllers\Controller;
use App\Mail\ProgramaDelEvento;
use App\Models\Event;
use App\Models\ProgramRequest;
use App\Support\Forms\ProgramFormField;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Personas que pidieron el programa desde el formulario público. Entran solas:
 * no se crean a mano. El trabajo es seguirlas hasta que se inscriben.
 */
class SolicitudController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ProgramRequest::class);

        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:120'],
            'evento' => ['nullable', 'integer'],
            'pendientes' => ['nullable', 'boolean'],
            'cargo' => ['nullable', 'string', 'max:120'],
        ]);

        $solicitudes = ProgramRequest::query()
            ->with('event')
            ->when($filtros['buscar'] ?? null, fn (Builder $q, string $buscar) => $q->where(
                fn (Builder $s) => $s->where('first_name', 'like', "%{$buscar}%")
                    ->orWhere('last_name', 'like', "%{$buscar}%")
                    ->orWhere('email', 'like', "%{$buscar}%")
                    ->orWhere('institution', 'like', "%{$buscar}%"),
            ))
            ->when($filtros['evento'] ?? null, fn (Builder $q, int $evento) => $q->where('event_id', $evento))
            ->when($filtros['pendientes'] ?? false, fn (Builder $q) => $q->whereNull('converted_at'))
            ->when($filtros['cargo'] ?? null, fn (Builder $q, string $cargo) => $q->where('position', $cargo))
            ->latest()
            ->paginate(15)
            ->withQueryString()
            ->through(fn (ProgramRequest $solicitud): array => [
                'id' => $solicitud->id,
                'recibida' => $solicitud->created_at->format('d-m-Y H:i'),
                'persona' => $solicitud->nombre_completo,
                'cargo' => $solicitud->position,
                'correo' => $solicitud->email,
                'institucion' => $solicitud->institution,
                'evento' => $solicitud->event?->name,
                'estado' => self::estado($solicitud),
            ]);

        return Inertia::render('solicitudes/index', [
            'solicitudes' => $solicitudes,
            'filtros' => [
                'buscar' => $filtros['buscar'] ?? null,
                'evento' => $filtros['evento'] ?? null,
                'pendientes' => (bool) ($filtros['pendientes'] ?? false),
                'cargo' => $filtros['cargo'] ?? null,
            ],
            'eventos' => Event::query()->orderBy('name')->pluck('name', 'id'),
            'exportacion' => $request->user()->can(Permiso::ExportarContactos->value) ? [
                'cargos' => ProgramFormField::CARGOS,
                'tipos' => ExportarContactos::TIPOS,
                'pagos' => collect(PaymentStatus::cases())->mapWithKeys(fn (PaymentStatus $e): array => [$e->value => $e->label()]),
            ] : null,
            // Los cargos que existen de verdad en la base, no la lista teórica:
            // filtrar por uno que nadie eligió devuelve una pantalla vacía y
            // parece que el filtro está roto.
            'cargos' => ProgramRequest::query()
                ->whereNotNull('position')
                ->distinct()
                ->orderBy('position')
                ->pluck('position'),
        ]);
    }

    public function show(Request $request, ProgramRequest $programRequest): Response
    {
        Gate::authorize('view', $programRequest);

        $programRequest->load(['event', 'accessType']);
        $puedeGestionar = $request->user()->can('update', $programRequest);

        return Inertia::render('solicitudes/show', [
            'solicitud' => [
                'id' => $programRequest->id,
                'persona' => $programRequest->nombre_completo,
                'correo' => $programRequest->email,
                'cargo' => $programRequest->position,
                'institucion' => $programRequest->institution,
                'telefono' => $programRequest->phone,
                'interes' => $programRequest->interest_label ?? $programRequest->accessType?->name,
                'evento' => $programRequest->event === null ? null : [
                    'id' => $programRequest->event->id,
                    'nombre' => $programRequest->event->name,
                ],
                'estado' => self::estado($programRequest),
                'inscrita' => $programRequest->yaConvertida(),
                'llego' => $programRequest->created_at->format('d-m-Y H:i'),
                'programa_enviado' => $programRequest->program_sent_at?->format('d-m-Y H:i'),
                'se_inscribio' => $programRequest->converted_at?->format('d-m-Y H:i'),
                'desde' => $programRequest->source_url,
                // Los tres juntos: por separado no dicen nada y ocuparían tres
                // líneas de la ficha para responder una sola pregunta.
                'campana' => collect([
                    $programRequest->utm_source,
                    $programRequest->utm_medium,
                    $programRequest->utm_campaign,
                ])->filter()->implode(' · ') ?: null,
                'internal_notes' => $programRequest->internal_notes,
            ],
            'respuestas' => self::respuestas($programRequest),
            'puede' => [
                'gestionar' => $puedeGestionar,
                'eliminar' => $request->user()->can('delete', $programRequest),
            ],
        ]);
    }

    public function actualizarNotas(Request $request, ProgramRequest $programRequest): RedirectResponse
    {
        Gate::authorize('update', $programRequest);

        $programRequest->update($request->validate(['internal_notes' => ['nullable', 'string', 'max:5000']]));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Notas guardadas.']);

        return back();
    }

    /**
     * Se marca y se desmarca con el mismo botón: equivocarse tiene vuelta atrás.
     */
    public function alternarInscrita(ProgramRequest $programRequest): RedirectResponse
    {
        Gate::authorize('update', $programRequest);

        $marcada = $programRequest->yaConvertida();
        $programRequest->update(['converted_at' => $marcada ? null : now()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $marcada ? 'Marca deshecha.' : 'Anotado: ya se inscribió.']);

        return back();
    }

    /** El correo lleva el programa adjunto y el enlace para inscribirse. */
    public function reenviarPrograma(ProgramRequest $programRequest): RedirectResponse
    {
        Gate::authorize('update', $programRequest);

        Mail::to($programRequest->email)->queue(new ProgramaDelEvento($programRequest));
        $programRequest->update(['program_sent_at' => now()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Correo en camino a '.$programRequest->email.'.']);

        return back();
    }

    public function destroy(ProgramRequest $programRequest): RedirectResponse
    {
        Gate::authorize('delete', $programRequest);

        $programRequest->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Solicitud de {$programRequest->nombre_completo} eliminada."]);

        return to_route('solicitudes.index');
    }

    /**
     * Derivado de las fechas, no guardado: una columna de estado que hay que
     * sincronizar con esas mismas fechas termina contradiciéndolas.
     *
     * @return array{value: string, label: string, color: string}
     */
    private static function estado(ProgramRequest $solicitud): array
    {
        return [
            'value' => match (true) {
                $solicitud->converted_at !== null => 'inscrita',
                $solicitud->program_sent_at !== null => 'enviado',
                default => 'nueva',
            },
            'label' => $solicitud->estado(),
            'color' => $solicitud->colorDelEstado(),
        ];
    }

    /**
     * Las preguntas propias del formulario del evento, con su etiqueta y no con
     * la clave interna con que se guardaron.
     *
     * @return array<int, array{pregunta: string, respuesta: string}>
     */
    private static function respuestas(ProgramRequest $solicitud): array
    {
        $etiquetas = collect($solicitud->event?->program_form_fields ?: ProgramFormField::porDefectoComoArray())
            ->mapWithKeys(fn (array $campo): array => [$campo['key'] => $campo['label'] ?? $campo['key']]);

        return collect($solicitud->extra ?? [])
            ->filter(fn ($valor): bool => filled($valor))
            ->map(fn ($valor, string $clave): array => [
                'pregunta' => $etiquetas[$clave] ?? $clave,
                'respuesta' => is_array($valor) ? implode(', ', $valor) : (string) $valor,
            ])
            ->values()
            ->all();
    }
}
