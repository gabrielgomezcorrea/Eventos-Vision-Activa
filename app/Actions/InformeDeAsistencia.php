<?php

namespace App\Actions;

use App\Enums\OrderKind;
use App\Enums\PaymentStatus;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\Order;
use App\Models\Participant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Attendance report for certificates (19/11/2026): every participant of a paid
 * enrollment of one event (guests included), with one column per session of
 * the event, whether or not their access includes it.
 *
 * Accreditation is one scan per person and event, not per session. So the day
 * of the scan tells which session they first came to; earlier sessions of
 * their access were missed, and later ones have no record ("Sin registro"),
 * because nobody scanned them again.
 */
class InformeDeAsistencia
{
    public const ASISTIO = 'Asistió';

    public const NO_ASISTIO = 'No asistió';

    public const NO_INSCRITO = 'No inscrito';

    public const SIN_REGISTRO = 'Sin registro';

    /** @return array<string, array<int, array<int, string|int|null>>> */
    public function __invoke(Event $evento, bool $soloAsistieron = false): array
    {
        $jornadas = $evento->sessions()->orderBy('position')->get();

        // Paid enrollments, plus anyone accredited whatever their payment says:
        // nobody who came may be left out of the certificates.
        $ordenes = Order::query()
            ->where('event_id', $evento->id)
            ->where(fn (Builder $q) => $q->where('payment_status', PaymentStatus::Aprobado->value)
                ->orWhereHas('participants.accreditation'))
            ->with([
                'establishments', 'payerEntity',
                'participantesVigentes' => fn ($q) => $q->with(['accessType.sessions', 'accreditation', 'establishment'])->orderBy('last_name')->orderBy('first_name'),
            ])
            ->orderBy('id')
            ->get();

        $filas = [[
            'Folio', 'Nombre', 'Apellidos', 'RUT', 'Correo', 'Teléfono', 'Cargo', 'Colegio', 'Región',
            'Entidad pagadora', 'RUT entidad', 'Acceso', 'Invitado', 'Asistió al evento', 'Llegada',
            ...$jornadas->map(fn (EventSession $j): string => $j->name)->all(),
        ]];

        foreach ($ordenes as $orden) {
            $colegio = $orden->establishments->first();

            foreach ($orden->participantesVigentes as $persona) {
                /** @var Participant $persona */
                $llegada = $persona->accreditation?->accredited_at;

                if ($soloAsistieron && $llegada === null) {
                    continue;
                }

                $establecimiento = $persona->establishment ?? $colegio;

                $filas[] = [
                    $orden->number,
                    $persona->first_name,
                    $persona->last_name,
                    $persona->rut,
                    $persona->email,
                    $persona->phone,
                    $persona->position,
                    $establecimiento?->name,
                    $establecimiento?->region,
                    $orden->payerEntity?->name,
                    $orden->payerEntity?->rut,
                    $persona->accessType?->name ?? 'Sin acceso',
                    $orden->kind === OrderKind::Invitado ? 'Sí' : 'No',
                    $llegada ? 'Sí' : 'No',
                    $llegada?->format('d-m-Y H:i'),
                    ...$this->porJornada($jornadas, $persona),
                ];
            }
        }

        return ['Asistencia' => $filas];
    }

    /**
     * @param  Collection<int, EventSession>  $jornadas
     * @return array<int, string>
     */
    private function porJornada(Collection $jornadas, Participant $persona): array
    {
        $incluidas = $jornadas->whereIn('id', $persona->accessType?->sessions->pluck('id') ?? [])->values();
        $llegada = $persona->accreditation?->accredited_at;

        // The session they came to: the first of their access held on the day
        // of the scan; with no date match (sessions without dates), the first.
        $asistida = $llegada === null ? null
            : ($incluidas->first(fn (EventSession $j): bool => $j->starts_at?->isSameDay($llegada) ?? false) ?? $incluidas->first());
        $posicion = $asistida === null ? null : $incluidas->search(fn (EventSession $j): bool => $j->is($asistida));

        return $jornadas->map(function (EventSession $jornada) use ($incluidas, $asistida, $posicion): string {
            $indice = $incluidas->search(fn (EventSession $j): bool => $j->is($jornada));

            return match (true) {
                $indice === false => self::NO_INSCRITO,
                $asistida === null => self::NO_ASISTIO,
                $indice === $posicion => self::ASISTIO,
                $indice < $posicion => self::NO_ASISTIO,
                default => self::SIN_REGISTRO,
            };
        })->all();
    }
}
