<?php

namespace App\Http\Controllers\Solicitudes;

use App\Actions\EliminarSolicitudConTodo;
use App\Actions\EmitirEnlaceDeAcceso;
use App\Actions\ExportarContactos;
use App\Enums\PaymentStatus;
use App\Enums\Permiso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Solicitudes\GuardarNotaRequest;
use App\Mail\ProgramaDelEvento;
use App\Models\Event;
use App\Models\ProgramRequest;
use App\Support\Forms\ProgramFormField;
use App\Support\Presentar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
            'seguimiento' => ['nullable', 'in:sin,con'],
        ]);

        $solicitudes = ProgramRequest::query()
            ->with('event')
            ->withCount('notes')
            ->withMax('notes', 'created_at')
            ->when($filtros['buscar'] ?? null, fn (Builder $q, string $buscar) => $q->where(
                fn (Builder $s) => $s->where('first_name', 'like', "%{$buscar}%")
                    ->orWhere('last_name', 'like', "%{$buscar}%")
                    ->orWhere('email', 'like', "%{$buscar}%")
                    ->orWhere('institution', 'like', "%{$buscar}%"),
            ))
            ->when($filtros['evento'] ?? null, fn (Builder $q, int $evento) => $q->where('event_id', $evento))
            ->when($filtros['pendientes'] ?? false, fn (Builder $q) => $q->whereNull('converted_at'))
            ->when($filtros['cargo'] ?? null, fn (Builder $q, string $cargo) => $q->where('position', $cargo))
            // Sin seguimiento: nadie la ha contactado ni se ha inscrito todavía.
            ->when(($filtros['seguimiento'] ?? null) === 'sin', fn (Builder $q) => $q->whereDoesntHave('notes')->whereNull('converted_at'))
            ->when(($filtros['seguimiento'] ?? null) === 'con', fn (Builder $q) => $q->whereHas('notes'))
            ->latest()
            ->paginate(15)
            ->withQueryString()
            ->through(fn (ProgramRequest $solicitud): array => [
                'id' => $solicitud->id,
                'recibida' => Presentar::fecha($solicitud->created_at),
                'recibida_hora' => $solicitud->created_at->format('H:i'),
                'persona' => $solicitud->nombre_completo,
                'cargo' => $solicitud->position,
                'correo' => $solicitud->email,
                'telefono' => $solicitud->phone,
                'institucion' => $solicitud->institution,
                'evento' => $solicitud->event?->name,
                'estado' => self::estado($solicitud),
                'ultima_nota' => $solicitud->getAttribute('notes_max_created_at') === null ? null : Carbon::parse($solicitud->getAttribute('notes_max_created_at'))->format('d-m H:i'),
            ]);

        return Inertia::render('solicitudes/index', [
            'solicitudes' => $solicitudes,
            'filtros' => [
                'buscar' => $filtros['buscar'] ?? null,
                'evento' => $filtros['evento'] ?? null,
                'pendientes' => (bool) ($filtros['pendientes'] ?? false),
                'cargo' => $filtros['cargo'] ?? null,
                'seguimiento' => $filtros['seguimiento'] ?? null,
            ],
            'eventos' => Event::query()->orderBy('name')->pluck('name', 'id'),
            'exportacion' => $request->user()->can(Permiso::ExportarContactos->value) ? [
                'cargos' => ProgramFormField::todosLosCargos(),
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

        $programRequest->load(['event', 'accessType', 'notes.user']);
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
                'llego' => Presentar::fechaYHora($programRequest->created_at),
                'programa_enviado' => $programRequest->program_sent_at ? Presentar::fechaYHora($programRequest->program_sent_at) : null,
                'se_inscribio' => $programRequest->converted_at ? Presentar::fechaYHora($programRequest->converted_at) : null,
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
            'notas' => $programRequest->notes->map(fn ($nota): array => [
                'id' => $nota->id,
                'fecha' => Presentar::fecha($nota->created_at).' - '.$nota->created_at->format('H:i:s'),
                'autor' => $nota->user?->name,
                'texto' => $nota->body,
            ])->values()->all(),
            'respuestas' => self::respuestas($programRequest),
            'puede' => [
                'gestionar' => $puedeGestionar,
                'eliminar' => $request->user()->can('delete', $programRequest),
                'eliminar_todo' => $request->user()->can('deleteAll', $programRequest),
            ],
            // Lo que "Eliminar todo" borraría, para decirlo antes de confirmar.
            'relacionado' => $request->user()->can('deleteAll', $programRequest) ? EliminarSolicitudConTodo::resumen($programRequest) : null,
        ]);
    }

    public function guardarNota(GuardarNotaRequest $request, ProgramRequest $programRequest): RedirectResponse
    {
        $programRequest->notes()->create([
            'user_id' => $request->user()->getKey(),
            'body' => $request->validated('body'),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Nota guardada.']);

        return back();
    }

    public function actualizarNotas(Request $request, ProgramRequest $programRequest): RedirectResponse
    {
        Gate::authorize('update', $programRequest);

        $programRequest->update($request->validate(['internal_notes' => ['nullable', 'string', 'max:5000']]));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Notas guardadas.']);

        return back();
    }

    /** El correo lleva el programa adjunto y el enlace para inscribirse. */
    public function reenviarPrograma(ProgramRequest $programRequest): RedirectResponse
    {
        Gate::authorize('update', $programRequest);

        Mail::to($programRequest->email)->queue(new ProgramaDelEvento(
            $programRequest,
            app(EmitirEnlaceDeAcceso::class)->urlDeAcceso($programRequest),
        ));
        $programRequest->registrarEnvioDelPrograma();

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

    public function destroyAll(ProgramRequest $programRequest, EliminarSolicitudConTodo $eliminar): RedirectResponse
    {
        Gate::authorize('deleteAll', $programRequest);

        $nombre = $programRequest->nombre_completo;
        $resultado = $eliminar($programRequest);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Solicitud de {$nombre} eliminada con {$resultado['inscripciones']} inscripción(es) y todo lo relacionado."]);

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
                $solicitud->tieneNotas() => 'contactada',
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
