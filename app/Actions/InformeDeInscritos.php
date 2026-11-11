<?php

namespace App\Actions;

use App\Enums\OrderKind;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Inscripciones\InscripcionController;
use App\Models\Order;
use App\Models\Participant;
use Illuminate\Database\Eloquent\Builder;

/**
 * The enrollment report the boss asked for (10/11/2026): one sheet with one row
 * per enrollment and one with one row per participant. Unlike the contacts
 * export it does not merge people by email: here every seat counts.
 *
 * Every enrollment except empty drafts, each with its state, so whoever reads
 * it filters in Excel instead of asking for another export.
 */
class InformeDeInscritos
{
    /**
     * @param  array{evento?: int|null, buscar?: string|null}  $filtros
     * @return array<string, array<int, array<int, string|int|null>>>
     */
    public function __invoke(array $filtros): array
    {
        $ordenes = Order::query()
            ->whereNot(fn (Builder $q) => $q->borradorVacio())
            ->when($filtros['buscar'] ?? null, fn (Builder $q, string $buscar) => $q->where(
                fn (Builder $o) => $o->where('number', 'like', "%{$buscar}%")
                    ->orWhere('responsible_name', 'like', "%{$buscar}%")
                    ->orWhere('responsible_lastname', 'like', "%{$buscar}%")
                    ->orWhere('responsible_email', 'like', "%{$buscar}%")
                    ->orWhereHas('payerEntity', fn (Builder $p) => $p->where('name', 'like', "%{$buscar}%")),
            ))
            ->when($filtros['evento'] ?? null, fn (Builder $q, int $evento) => $q->where('event_id', $evento))
            ->withSum(['payments as aprobado' => fn (Builder $q) => $q->where('status', PaymentStatus::Aprobado->value)], 'amount')
            ->with([
                'event:id,name', 'payerEntity', 'establishments', 'discountCode:id,code',
                'participantesVigentes' => fn ($q) => $q->with(['accessType:id,name', 'accreditation', 'establishment:id,name'])->orderBy('id'),
            ])
            ->orderBy('event_id')
            ->orderBy('id')
            ->get();

        $inscripciones = [[
            'Folio', 'Evento', 'Estado', 'Responsable', 'Correo responsable', 'Teléfono responsable',
            'Colegio', 'Región', 'Entidad pagadora', 'RUT entidad', 'Participantes', 'Total', 'Pagado', 'Saldo',
            'Código de descuento', 'Confirmada', 'Creada',
        ]];

        $participantes = [[
            'Folio', 'Evento', 'Nombre', 'Apellidos', 'RUT', 'Correo', 'Teléfono', 'Cargo', 'Acceso', 'Valor',
            'Colegio', 'Entidad pagadora', 'Estado de la inscripción', 'Invitado', 'Acreditado',
        ]];

        foreach ($ordenes as $orden) {
            $pagado = (int) $orden->getAttribute('aprobado');
            $estado = InscripcionController::estadoDeLaFila($orden)['label'];
            $colegio = $orden->establishments->first();
            $borrador = $orden->esBorrador();

            $inscripciones[] = [
                $orden->number,
                $orden->event?->name,
                $estado,
                $orden->responsableNombreCompleto(),
                $orden->responsible_email,
                $orden->responsible_phone,
                $colegio?->name,
                $colegio?->region,
                $orden->payerEntity?->name,
                $orden->payerEntity?->rut,
                $orden->participantesVigentes->count(),
                $borrador ? null : $orden->total,
                $pagado,
                $borrador ? null : max(0, $orden->total - $pagado),
                $orden->discountCode?->code,
                $orden->confirmed_at?->format('d-m-Y H:i'),
                $orden->created_at?->format('d-m-Y H:i'),
            ];

            foreach ($orden->participantesVigentes as $participante) {
                /** @var Participant $participante */
                $participantes[] = [
                    $orden->number,
                    $orden->event?->name,
                    $participante->first_name,
                    $participante->last_name,
                    $participante->rut,
                    $participante->email,
                    $participante->phone,
                    $participante->position,
                    $participante->accessType?->name,
                    $participante->access_type_id === null ? null : (int) $participante->unit_price,
                    ($participante->establishment ?? $colegio)?->name,
                    $orden->payerEntity?->name,
                    $estado,
                    $orden->kind === OrderKind::Invitado ? 'Sí' : 'No',
                    $participante->accreditation?->accredited_at?->format('d-m-Y H:i'),
                ];
            }
        }

        return ['Inscripciones' => $inscripciones, 'Participantes' => $participantes];
    }
}
