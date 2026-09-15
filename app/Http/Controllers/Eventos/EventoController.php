<?php

namespace App\Http\Controllers\Eventos;

use App\Console\Commands\RecordarReservas;
use App\Enums\EventModality;
use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Permiso;
use App\Enums\ReservationDurationUnit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Eventos\ActualizarEventoRequest;
use App\Http\Requests\Eventos\CambiarEstadoEventoRequest;
use App\Http\Requests\Eventos\CrearEventoRequest;
use App\Models\AccessType;
use App\Models\BankAccount;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\Payment;
use App\Support\Forms\ProgramFormField;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EventoController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Event::class);

        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:120'],
            'estado' => ['nullable', Rule::enum(EventStatus::class)],
            'modalidad' => ['nullable', Rule::enum(EventModality::class)],
        ]);

        $eventos = Event::query()
            ->withCount(['sessions', 'accessTypes'])
            ->when($filtros['buscar'] ?? null, fn (Builder $q, string $buscar) => $q->where('name', 'like', "%{$buscar}%"))
            ->when($filtros['estado'] ?? null, fn (Builder $q, string $estado) => $q->where('status', $estado))
            ->when($filtros['modalidad'] ?? null, fn (Builder $q, string $modalidad) => $q->where('modality', $modalidad))
            ->latest()
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Event $evento): array => [
                'id' => $evento->id,
                'name' => $evento->name,
                'slug' => $evento->slug,
                'estado' => self::estado($evento->status),
                'modalidad' => $evento->modality->label(),
                'fecha' => $evento->starts_on?->format('d-m-Y'),
                'jornadas' => $evento->sessions_count,
                'accesos' => $evento->access_types_count,
                'reserva' => self::plazo($evento),
            ]);

        return Inertia::render('eventos/index', [
            'eventos' => $eventos,
            'filtros' => (object) array_filter($filtros),
            'estados' => EventStatus::options(),
            'modalidades' => EventModality::options(),
            'puedeCrear' => $request->user()->can('create', Event::class),
        ]);
    }

    public function store(CrearEventoRequest $request): RedirectResponse
    {
        $evento = Event::create([
            ...$request->validated(),
            'slug' => Event::identificadorLibre($request->validated('name')),
            'status' => EventStatus::Borrador,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Evento creado. Ahora agrega sus jornadas y tipos de acceso.']);

        return to_route('eventos.show', $evento);
    }

    public function show(Request $request, Event $event): Response
    {
        Gate::authorize('view', $event);

        $event->load(['sessions', 'accessTypes.sessions', 'bankAccount']);
        $usuario = $request->user();

        return Inertia::render('eventos/show', [
            'evento' => [
                'id' => $event->id,
                'name' => $event->name,
                'slug' => $event->slug,
                'description' => $event->description,
                'modality' => $event->modality->value,
                'modalidad' => $event->modality->label(),
                'starts_on' => $event->starts_on?->format('Y-m-d'),
                'reminder_hours_before' => $event->reminder_hours_before,
                'aviso' => ($event->reminder_hours_before ?? RecordarReservas::HORAS_POR_DEFECTO).' horas antes'
                    .($event->reminder_hours_before === null ? ' (por defecto)' : ''),
                'fecha' => $event->starts_on?->translatedFormat('d \d\e F \d\e Y'),
                'estado' => self::estado($event->status),
                'location' => $event->location,
                'address' => $event->address,
                'region' => $event->region,
                'commune' => $event->commune,
                'city' => $event->city,
                'reservation_duration_value' => $event->reservation_duration_value,
                'reservation_duration_unit' => $event->reservation_duration_unit->value,
                'reserva' => self::plazo($event),
                'replacement_deadline' => $event->replacement_deadline?->format('Y-m-d'),
                'fecha_limite_reemplazos' => $event->replacement_deadline?->format('d/m/Y'),
                'bank_account_id' => $event->bank_account_id,
                'cuenta' => $event->bankAccount === null ? null : [
                    'label' => $event->bankAccount->label,
                    'bank_name' => $event->bankAccount->bank_name,
                    'account_number' => $event->bankAccount->account_number,
                    'holder_name' => $event->bankAccount->holder_name,
                    'holder_rut' => $event->bankAccount->holder_rut,
                    'account_type' => $event->bankAccount->account_type,
                    'payment_instructions' => $event->bankAccount->payment_instructions,
                ],
                'enlace_publico' => route('publico.programa', ['event' => $event->slug]),
                'usa_lugar' => $event->modality !== EventModality::Online,
            ],
            'resumen' => self::resumen($event, $usuario->can(Permiso::VerComprobantes->value)),
            'jornadas' => $event->sessions->map(fn (EventSession $jornada): string => self::describirJornada($jornada))->values()->all(),
            'accesos' => $event->accessTypes->map(fn (AccessType $acceso): string => self::describirAcceso($acceso))->values()->all(),
            'formulario' => collect($event->camposDelFormulario())
                ->map(fn (ProgramFormField $campo): string => $campo->label.($campo->required ? ' (obligatorio)' : ''))
                ->all(),
            'faltaParaPublicar' => $event->loQueFaltaParaPublicar(),
            'opciones' => [
                'modalidades' => EventModality::options(),
                'unidades' => ReservationDurationUnit::options(),
                'regiones' => config('chile'),
                'cuentas' => BankAccount::query()
                    ->where(fn (Builder $q) => $q->where('is_active', true)->orWhere('id', $event->bank_account_id))
                    ->orderBy('label')
                    ->get()
                    ->map(fn (BankAccount $cuenta): array => ['id' => $cuenta->id, 'label' => $cuenta->etiquetaCompleta()])
                    ->all(),
                'estados' => [
                    ['value' => EventStatus::Borrador->value, 'label' => EventStatus::Borrador->label(), 'descripcion' => 'Todavía no recibe inscripciones. Es como nace un evento nuevo.'],
                    ['value' => EventStatus::Publicado->value, 'label' => EventStatus::Publicado->label(), 'descripcion' => 'La gente puede inscribirse desde el formulario público.'],
                    ['value' => EventStatus::Cerrado->value, 'label' => EventStatus::Cerrado->label(), 'descripcion' => 'Deja de aceptar inscripciones nuevas. Las que ya existen no se tocan.'],
                ],
            ],
            'puede' => [
                'editar' => $usuario->can('update', $event),
                'eliminar' => $usuario->can('delete', $event),
            ],
        ]);
    }

    public function update(ActualizarEventoRequest $request, Event $event): RedirectResponse
    {
        $event->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cambios guardados.']);

        return back();
    }

    public function cambiarEstado(CambiarEstadoEventoRequest $request, Event $event): RedirectResponse
    {
        $estado = EventStatus::from($request->validated('status'));

        if ($estado !== $event->status) {
            $event->update(['status' => $estado]);

            Inertia::flash('toast', ['type' => 'success', 'message' => $estado === EventStatus::Publicado
                ? 'Evento publicado. Ya puede recibir inscripciones.'
                : 'Estado cambiado a '.mb_strtolower($estado->label()).'.']);
        }

        return back();
    }

    public function destroy(Event $event): RedirectResponse
    {
        Gate::authorize('delete', $event);

        $event->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Evento «{$event->name}» eliminado."]);

        return to_route('eventos.index');
    }

    /**
     * @return array{value: string, label: string, color: string}
     */
    public static function estado(EventStatus $estado): array
    {
        return ['value' => $estado->value, 'label' => $estado->label(), 'color' => $estado->color()];
    }

    private static function plazo(Event $evento): string
    {
        return $evento->reservation_duration_value.' '.mb_strtolower($evento->reservation_duration_unit->label());
    }

    /**
     * La ventana del evento hacia lo suyo: cada número lleva a su lista filtrada.
     *
     * @return array<string, int|null>
     */
    private static function resumen(Event $evento, bool $verComprobantes): array
    {
        return [
            'solicitudes' => $evento->programRequests()->count(),
            'solicitudes_sin_seguir' => $evento->programRequests()->whereNull('converted_at')->count(),
            'inscripciones' => $evento->orders()->whereNotNull('number')->count(),
            'pagadas' => $evento->orders()->where('payment_status', PaymentStatus::Aprobado)->count(),
            'por_vencer' => $evento->orders()
                ->where('status', OrderStatus::Reservada)
                ->whereNotIn('payment_status', [PaymentStatus::EnValidacion->value, PaymentStatus::Aprobado->value])
                ->whereBetween('reserved_until', [now(), now()->addHours(48)])
                ->count(),
            'comprobantes' => $verComprobantes
                ? Payment::query()
                    ->where('status', PaymentStatus::EnValidacion)
                    ->whereHas('order', fn (Builder $q) => $q->where('event_id', $evento->getKey()))
                    ->count()
                : null,
            'acreditados' => $evento->accreditations()->count(),
            'credenciales' => $evento->tickets()->whereNull('revoked_at')->count(),
        ];
    }

    /** Ej. «Jornada 1 · 14 nov, 09:00 · 120 cupos, quedan 38». */
    private static function describirJornada(EventSession $jornada): string
    {
        $partes = [$jornada->name];

        if ($jornada->starts_at !== null) {
            $partes[] = $jornada->starts_at->translatedFormat('d M, H:i');
        }

        $partes[] = $jornada->tieneCapacidadLimitada()
            ? $jornada->capacity.' cupos, quedan '.$jornada->cuposDisponibles()
            : 'sin límite de cupos';

        return implode(' · ', $partes);
    }

    /** Ej. «Ambas jornadas — $180.000 · incluye Jornada 1, Jornada 2». */
    private static function describirAcceso(AccessType $acceso): string
    {
        $texto = $acceso->name.' — $'.number_format($acceso->price, 0, ',', '.');

        $jornadas = $acceso->sessions->sortBy('position')->pluck('name')->all();

        $texto .= $jornadas === []
            ? ' · sin jornadas asignadas'
            : ' · incluye '.implode(', ', $jornadas);

        if (! $acceso->is_active) {
            $texto .= ' · no está a la venta';
        }

        return $texto;
    }
}
