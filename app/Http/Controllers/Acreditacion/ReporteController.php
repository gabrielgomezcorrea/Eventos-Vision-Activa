<?php

namespace App\Http\Controllers\Acreditacion;

use App\Enums\OrderKind;
use App\Enums\ParticipantStatus;
use App\Enums\Permiso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Acreditacion\ReporteAcreditacionRequest;
use App\Models\Accreditation;
use App\Models\Event;
use App\Models\Order;
use App\Models\Participant;
use App\Support\Presentar;
use App\Support\Rut;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reporte del día del evento: cuántos se esperaban, cuántos llegaron y cuántos
 * faltan, siempre de un evento a la vez y sin montos. Se espera a quien tiene
 * credencial emitida (pago aprobado) o ya fue acreditado; reemplazados y sin
 * pago aprobado no cuentan.
 */
class ReporteController extends Controller
{
    private const ESPERADOS = [ParticipantStatus::CredencialEmitida, ParticipantStatus::Acreditado];

    public function index(ReporteAcreditacionRequest $request): Response
    {
        $filtros = $request->validated();
        $evento = $this->evento($filtros['evento'] ?? null);

        $props = [
            'eventos' => Event::query()->orderBy('name')->pluck('name', 'id'),
            'evento' => $evento?->id,
            'filtros' => [
                'estado' => $filtros['estado'] ?? null,
                'inscripcion' => $filtros['inscripcion'] ?? null,
                'buscar' => $filtros['buscar'] ?? null,
            ],
        ];

        if ($evento === null) {
            return Inertia::render('acreditaciones/index', $props + ['reporte' => null]);
        }

        $verAcreditador = $request->user()->can(Permiso::VerAuditoria->value);

        return Inertia::render('acreditaciones/index', $props + [
            'reporte' => [
                'totales' => $this->totales($evento),
                'jornadas' => $this->porJornada($evento),
                'llegadas' => $this->llegadasPorHora($evento),
                'colegios' => $this->porColegio($evento),
                'personas' => $this->personas($evento, $filtros, $verAcreditador),
                'ver_acreditador' => $verAcreditador,
            ],
        ]);
    }

    /** El publicado que empieza más pronto desde hoy; si no hay, el de inicio más reciente. */
    private function evento(?int $id): ?Event
    {
        if ($id !== null) {
            return Event::query()->find($id);
        }

        return Event::query()->where('status', 'published')->whereDate('starts_on', '>=', today())->orderBy('starts_on')->first()
            ?? Event::query()->orderByDesc('starts_on')->orderByDesc('id')->first();
    }

    /** @return Builder<Participant> */
    private function esperados(Event $evento): Builder
    {
        return Participant::query()
            ->whereIn('status', self::ESPERADOS)
            ->whereHas('order', fn (Builder $o) => $o->where('event_id', $evento->id));
    }

    /** @return array{esperados: int, llegaron: int, faltan: int, invitados: int, invitados_llegaron: int} */
    private function totales(Event $evento): array
    {
        $esperados = $this->esperados($evento)->count();
        $llegaron = $this->esperados($evento)->whereHas('accreditation')->count();
        $invitados = fn (): Builder => $this->esperados($evento)->whereHas('order', fn (Builder $o) => $o->where('kind', OrderKind::Invitado));

        return [
            'esperados' => $esperados,
            'llegaron' => $llegaron,
            'faltan' => $esperados - $llegaron,
            'invitados' => $invitados()->count(),
            'invitados_llegaron' => $invitados()->whereHas('accreditation')->count(),
        ];
    }

    /**
     * Se espera en una jornada a quien tiene un acceso que la incluye. La
     * acreditación es una por persona y evento: quien llegó cuenta como llegado
     * en todas sus jornadas.
     *
     * @return array<int, array{id: int, nombre: string, cuando: string|null, esperados: int, llegaron: int, faltan: int}>
     */
    private function porJornada(Event $evento): array
    {
        return $evento->sessions()->orderBy('position')->get()->map(function ($jornada) use ($evento): array {
            $incluidos = fn (): Builder => $this->esperados($evento)
                ->whereHas('accessType.sessions', fn (Builder $s) => $s->where('event_sessions.id', $jornada->id));

            $esperados = $incluidos()->count();
            $llegaron = $incluidos()->whereHas('accreditation')->count();

            return [
                'id' => $jornada->id,
                'nombre' => $jornada->name,
                'cuando' => $jornada->starts_at ? Presentar::fechaYHora($jornada->starts_at) : null,
                'esperados' => $esperados,
                'llegaron' => $llegaron,
                'faltan' => $esperados - $llegaron,
            ];
        })->all();
    }

    /**
     * Llegadas por día y hora. Solo se leen las horas de llegada (no las
     * filas) y se agrupan aquí, en la hora de la aplicación, para que el
     * resultado sea el mismo en SQLite y MySQL.
     *
     * @return array<int, array{hora: string, llegadas: int}>
     */
    private function llegadasPorHora(Event $evento): array
    {
        return Accreditation::query()->where('event_id', $evento->id)->orderBy('accredited_at')->pluck('accredited_at')
            ->countBy(fn ($instante): string => $instante->format('d-m H').':00')
            ->map(fn (int $llegadas, string $hora): array => ['hora' => $hora, 'llegadas' => $llegadas])
            ->values()
            ->all();
    }

    /** @return array<int, array{id: int, colegio: string, entidad: string|null, folio: string|null, esperados: int, llegaron: int, faltan: int}> */
    private function porColegio(Event $evento): array
    {
        $conteos = Participant::query()
            ->join('orders', 'orders.id', '=', 'participants.order_id')
            ->leftJoin('accreditations', 'accreditations.participant_id', '=', 'participants.id')
            ->where('orders.event_id', $evento->id)
            ->whereIn('participants.status', array_map(fn (ParticipantStatus $e): string => $e->value, self::ESPERADOS))
            ->groupBy('participants.order_id')
            ->select('participants.order_id', DB::raw('count(*) as esperados'), DB::raw('sum(case when accreditations.id is null then 0 else 1 end) as llegaron'))
            ->get()
            ->keyBy('order_id');

        return Order::query()->with(['establishments', 'payerEntity'])->whereIn('id', $conteos->keys())->get()
            ->map(function (Order $orden) use ($conteos): array {
                $fila = $conteos->get($orden->id);
                $esperados = (int) $fila?->getAttribute('esperados');
                $llegaron = (int) $fila?->getAttribute('llegaron');

                return [
                    'id' => $orden->id,
                    'colegio' => $orden->nombreDelColegio() ?? $orden->responsableNombreCompleto(),
                    'entidad' => $orden->payerEntity?->name,
                    'folio' => $orden->number,
                    'esperados' => $esperados,
                    'llegaron' => $llegaron,
                    'faltan' => $esperados - $llegaron,
                ];
            })
            ->sortBy('colegio', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return mixed
     */
    private function personas(Event $evento, array $filtros, bool $verAcreditador)
    {
        return $this->esperados($evento)
            ->with(['order.establishments', 'order.payerEntity', 'accessType', 'accreditation'])
            ->when($filtros['estado'] ?? null, fn (Builder $q, string $estado) => $estado === 'llegaron'
                ? $q->whereHas('accreditation')
                : $q->whereDoesntHave('accreditation'))
            ->when($filtros['inscripcion'] ?? null, fn (Builder $q, int $orden) => $q->where('order_id', $orden))
            ->when($filtros['buscar'] ?? null, function (Builder $q, string $buscar): void {
                $rut = Rut::normalizar($buscar);

                $q->where(function (Builder $w) use ($buscar, $rut): void {
                    // Cada palabra debe estar en el nombre o en los apellidos.
                    $w->where(function (Builder $nombre) use ($buscar): void {
                        foreach (preg_split('/\s+/', trim($buscar), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $palabra) {
                            $nombre->where(fn (Builder $p) => $p->where('first_name', 'like', "%{$palabra}%")->orWhere('last_name', 'like', "%{$palabra}%"));
                        }
                    });

                    if ($rut !== null) {
                        $w->orWhere('rut', $rut);
                    }
                });
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(15)
            ->withQueryString()
            ->through(function (Participant $persona) use ($verAcreditador): array {
                $llegada = $persona->accreditation;

                return [
                    'id' => $persona->id,
                    'nombre' => $persona->nombre_completo,
                    'rut' => $persona->rut,
                    'colegio' => $persona->order->nombreDelColegio() ?? $persona->order->responsableNombreCompleto(),
                    'acceso' => $persona->accessType->name,
                    'llego' => $llegada !== null,
                    'invitado' => $persona->order->kind === OrderKind::Invitado,
                    'hora' => $llegada ? Presentar::fechaYHora($llegada->accredited_at) : null,
                    // Solo quien puede ver la auditoría; para los demás ni siquiera viaja.
                ] + ($verAcreditador ? [
                    'acreditado_por' => $llegada?->user_label,
                    'metodo' => $llegada ? ($llegada->method === 'qr' ? 'QR' : 'Manual') : null,
                ] : []);
            });
    }
}
