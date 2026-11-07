<?php

namespace App\Http\Controllers\Solicitudes;

use App\Actions\EliminarSolicitudConTodo;
use App\Actions\EmitirEnlaceDeAcceso;
use App\Actions\ExportarContactos;
use App\Enums\EtapaDeSeguimiento;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Permiso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Solicitudes\ActualizarSolicitudRequest;
use App\Http\Requests\Solicitudes\GuardarNotaRequest;
use App\Mail\ProgramaDelEvento;
use App\Models\Event;
use App\Models\ProgramRequest;
use App\Models\ProgramRequestNote;
use App\Support\Auditor;
use App\Support\Forms\ProgramFormField;
use App\Support\Presentar;
use App\Support\ReglasDeContacto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
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
            'etapa' => ['nullable', Rule::enum(EtapaDeSeguimiento::class)],
            // Solo aplica en «Solicitó»: sin notas (Nueva solicitud) o con notas (Contactado).
            'seguimiento' => ['nullable', 'in:sin,contactado'],
        ]);

        // Por defecto, quienes faltan por seguir: es el trabajo del día.
        $etapa = EtapaDeSeguimiento::tryFrom($filtros['etapa'] ?? '') ?? EtapaDeSeguimiento::Solicito;
        $seguimiento = $etapa === EtapaDeSeguimiento::Solicito ? ($filtros['seguimiento'] ?? null) : null;

        $base = fn (): Builder => ProgramRequest::query()->delListado($filtros);

        $solicitudes = $base()
            ->enEtapa($etapa)
            ->deSeguimiento($seguimiento)
            ->with('event')
            ->withCount('notes')
            ->withMax('notes', 'created_at')
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $ordenes = ProgramRequest::ordenesDe($solicitudes->getCollection());

        $solicitudes->through(function (ProgramRequest $solicitud) use ($etapa, $ordenes): array {
            $contactada = $etapa === EtapaDeSeguimiento::Solicito && $solicitud->tieneNotas();

            return [
                'id' => $solicitud->id,
                'recibida' => Presentar::fecha($solicitud->created_at),
                'recibida_hora' => $solicitud->created_at->format('H:i'),
                'persona' => $solicitud->nombreParaMostrar(),
                'directa' => $solicitud->esDirecta(),
                'cargo' => $solicitud->datoOResponsable('position', 'responsible_position'),
                'correo' => $solicitud->email,
                'telefono' => $solicitud->datoOResponsable('phone', 'responsible_phone'),
                'institucion' => $solicitud->institucionParaMostrar(),
                'evento' => $solicitud->event?->name,
                // Already followed up: green, so the team sees who is done at a glance.
                'estado' => $contactada
                    ? ['value' => 'contactado', 'label' => 'Contactado', 'color' => 'success']
                    : Presentar::estado($etapa),
                'detalle' => match (true) {
                    $contactada => null,
                    $etapa === EtapaDeSeguimiento::Solicito => $solicitud->seguimiento(),
                    $etapa === EtapaDeSeguimiento::PreInscrito => ProgramRequest::detalleDePreInscrito($ordenes[$solicitud->id] ?? collect()),
                    default => null,
                },
                'ultima_nota' => $solicitud->getAttribute('notes_max_created_at') === null ? null : Carbon::parse($solicitud->getAttribute('notes_max_created_at'))->format('d-m H:i'),
            ];
        });

        return Inertia::render('solicitudes/index', [
            'solicitudes' => $solicitudes,
            'filtros' => [
                'buscar' => $filtros['buscar'] ?? null,
                'evento' => $filtros['evento'] ?? null,
                'etapa' => $etapa->value,
                'seguimiento' => $seguimiento,
            ],
            // Cuántas hay en cada etapa con la búsqueda y el evento de ahora:
            // así se ve dónde quedó alguien sin tener que probar cada botón.
            'etapas' => collect(EtapaDeSeguimiento::cases())->map(fn (EtapaDeSeguimiento $e): array => [
                ...Presentar::estado($e),
                'label' => $e->plural(),
                'descripcion' => $e->descripcion(),
                'cantidad' => $base()->enEtapa($e)->count(),
            ])->all(),
            'eventos' => Event::query()->orderBy('name')->pluck('name', 'id'),
            'exportacion' => $request->user()->can(Permiso::ExportarContactos->value) ? [
                'cargos' => ProgramFormField::todosLosCargos(),
                'tipos' => ExportarContactos::TIPOS,
                'pagos' => collect(PaymentStatus::cases())->mapWithKeys(fn (PaymentStatus $e): array => [$e->value => $e->label()]),
            ] : null,
        ]);
    }

    public function show(Request $request, ProgramRequest $programRequest): Response
    {
        Gate::authorize('view', $programRequest);

        $programRequest->load(['event', 'accessType', 'notes.user', 'orders:id,program_request_id,responsible_name,responsible_lastname,responsible_phone,responsible_position,responsible_institution']);
        $puedeGestionar = $request->user()->can('update', $programRequest);

        return Inertia::render('solicitudes/show', [
            'solicitud' => [
                'id' => $programRequest->id,
                'persona' => $programRequest->nombreParaMostrar(),
                'directa' => $programRequest->esDirecta(),
                'correo' => $programRequest->email,
                'cargo' => $programRequest->datoOResponsable('position', 'responsible_position'),
                'institucion' => $programRequest->institucionParaMostrar(),
                'telefono' => $programRequest->datoOResponsable('phone', 'responsible_phone'),
                'interes' => $programRequest->interest_label ?? $programRequest->accessType?->name,
                'evento' => $programRequest->event === null ? null : [
                    'id' => $programRequest->event->id,
                    'nombre' => $programRequest->event->name,
                ],
                'estado' => Presentar::estado($etapa = $programRequest->etapa()),
                'inscrita' => $etapa === EtapaDeSeguimiento::Inscrito,
                'no_participara' => $programRequest->noParticipara(),
                'llego' => Presentar::fechaYHora($programRequest->created_at),
                'programa_enviado' => $programRequest->program_sent_at ? Presentar::fechaYHora($programRequest->program_sent_at) : null,
                'confirmo' => $programRequest->converted_at ? Presentar::fechaYHora($programRequest->converted_at) : null,
                'desde' => $programRequest->source_url,
                // Los tres juntos: por separado no dicen nada y ocuparían tres
                // líneas de la ficha para responder una sola pregunta.
                'campana' => collect([
                    $programRequest->utm_source,
                    $programRequest->utm_medium,
                    $programRequest->utm_campaign,
                ])->filter()->implode(' · ') ?: null,
                'internal_notes' => $programRequest->internal_notes,
                'inscripcion_id' => $this->inscripcionDe($request, $programRequest),
            ],
            'notas' => $programRequest->notes->map(fn ($nota): array => [
                'id' => $nota->id,
                'fecha' => Presentar::fecha($nota->created_at).' - '.$nota->created_at->format('H:i:s'),
                'autor' => $nota->user?->name,
                'texto' => $nota->body,
                'tipo' => $nota->etiquetaDelTipo(),
            ])->values()->all(),
            'respuestas' => self::respuestas($programRequest),
            // Valores del lápiz de "Datos del solicitante", tal como están guardados.
            'editar' => [
                // Precargado con lo que ya se sabe: si la solicitud no lo trae, lo
                // que escribió el responsable en su inscripción.
                'first_name' => $programRequest->datoOResponsable('first_name', 'responsible_name') ?? '',
                'last_name' => $programRequest->datoOResponsable('last_name', 'responsible_lastname') ?? '',
                'phone' => $programRequest->datoOResponsable('phone', 'responsible_phone') ?? '',
                'position' => '',
                'position_otro' => '',
                ...array_map(fn ($v) => $v ?? '', ReglasDeContacto::separarCargo($programRequest->datoOResponsable('position', 'responsible_position'))),
                'institution' => $programRequest->institucionParaMostrar() ?? '',
            ],
            'cargos' => ProgramFormField::CARGOS,
            'puede' => [
                'gestionar' => $puedeGestionar,
                'eliminar' => $request->user()->can('delete', $programRequest),
                'eliminar_todo' => $request->user()->can('deleteAll', $programRequest),
            ],
            // Lo que "Eliminar todo" borraría, para decirlo antes de confirmar.
            'relacionado' => $request->user()->can('deleteAll', $programRequest) ? EliminarSolicitudConTodo::resumen($programRequest) : null,
        ]);
    }

    public function update(ActualizarSolicitudRequest $request, ProgramRequest $programRequest): RedirectResponse
    {
        $programRequest->fill($request->datosNormalizados());
        $antes = array_intersect_key($programRequest->getOriginal(), $programRequest->getDirty());
        $despues = $programRequest->getDirty();

        if ($despues !== []) {
            $programRequest->save();

            Auditor::registrar(
                sobre: $programRequest,
                accion: 'solicitud.corregida',
                propiedades: ['antes' => $antes, 'despues' => $despues],
            );
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Datos de '.$programRequest->nombreParaMostrar().' guardados.']);

        return back();
    }

    public function guardarNota(GuardarNotaRequest $request, ProgramRequest $programRequest): RedirectResponse
    {
        $tipo = $request->validated('tipo');

        DB::transaction(function () use ($request, $programRequest, $tipo): void {
            $programRequest->notes()->create([
                'user_id' => $request->user()->getKey(),
                'body' => $request->validated('body'),
                'kind' => $tipo,
            ]);

            match ($tipo) {
                ProgramRequestNote::NO_PARTICIPARA => $programRequest->forceFill(['declined_at' => now()])->save(),
                ProgramRequestNote::VUELVE_A_SEGUIMIENTO => $programRequest->forceFill(['declined_at' => null])->save(),
                default => null,
            };
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => match ($tipo) {
            ProgramRequestNote::NO_PARTICIPARA => 'Marcada como «No participará».',
            ProgramRequestNote::VUELVE_A_SEGUIMIENTO => 'Volvió a seguimiento.',
            default => 'Nota guardada.',
        }]);

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

        Inertia::flash('toast', ['type' => 'success', 'message' => "Solicitud de {$programRequest->nombreParaMostrar()} eliminada."]);

        return to_route('solicitudes.index');
    }

    public function destroyAll(ProgramRequest $programRequest, EliminarSolicitudConTodo $eliminar): RedirectResponse
    {
        Gate::authorize('deleteAll', $programRequest);

        $nombre = $programRequest->nombreParaMostrar();
        $resultado = $eliminar($programRequest);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Solicitud de {$nombre} eliminada con {$resultado['inscripciones']} inscripción(es) y todo lo relacionado."]);

        return to_route('solicitudes.index');
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

    /**
     * La inscripción que salió de esta solicitud, para saltar a ella: la más
     * reciente ya confirmada y, si solo hay borradores, el último. Nulo si no
     * hay o si quien mira no puede ver inscripciones.
     */
    private function inscripcionDe(Request $request, ProgramRequest $solicitud): ?int
    {
        $orden = $solicitud->orders()
            ->orderByRaw('case when status = ? then 1 else 0 end', [OrderStatus::Borrador->value])
            ->latest('id')
            ->first();

        return $orden !== null && $request->user()->can('view', $orden) ? $orden->id : null;
    }
}
