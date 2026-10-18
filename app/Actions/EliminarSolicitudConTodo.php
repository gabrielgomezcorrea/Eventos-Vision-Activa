<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\DiscountCode;
use App\Models\Establishment;
use App\Models\Invitation;
use App\Models\MagicLink;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\Participant;
use App\Models\PayerEntity;
use App\Models\Payment;
use App\Models\ProgramRequest;
use App\Support\Auditor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Borra una solicitud y TODO lo que esa persona generó en el evento, para
 * limpiar datos de prueba: sus inscripciones (con participantes, pagos,
 * comprobantes, facturas, credenciales y acreditaciones), sus enlaces e
 * invitaciones. Lo relacionado se busca por el correo de la solicitud en el
 * mismo evento. Devuelve cupos y usos de código. Es irreversible: solo
 * Administración, y deja una constancia en la auditoría del evento.
 */
class EliminarSolicitudConTodo
{
    public function __construct(private ReservarCupos $cupos) {}

    /**
     * Lo que se borraría, sin tocar nada: sirve para avisar antes de confirmar.
     *
     * @return array{inscripciones: int, participantes: int, pagos: int}
     */
    public static function resumen(ProgramRequest $solicitud): array
    {
        $ordenes = self::ordenes($solicitud)->pluck('id');

        return [
            'inscripciones' => $ordenes->count(),
            'participantes' => Participant::whereIn('order_id', $ordenes)->count(),
            'pagos' => Payment::whereIn('order_id', $ordenes)->count(),
        ];
    }

    /** @return Collection<int, Order> */
    private static function ordenes(ProgramRequest $solicitud): Collection
    {
        return Order::query()
            ->where('event_id', $solicitud->event_id)
            ->where('responsible_email', mb_strtolower(trim($solicitud->email)))
            ->get();
    }

    /** @return array{inscripciones: int, folios: array<int, string>} */
    public function __invoke(ProgramRequest $solicitud): array
    {
        $archivos = [];

        $resultado = DB::transaction(function () use ($solicitud, &$archivos): array {
            $ordenes = self::ordenes($solicitud)->load(['participantesVigentes.accessType.sessions', 'payments.proofs', 'invoiceRecords']);
            $ids = $ordenes->modelKeys();
            $folios = $ordenes->pluck('number')->filter()->values()->all();
            $grupos = $ordenes->pluck('group_id')->filter()->unique()->values()->all();
            $pagadores = $ordenes->pluck('payer_entity_id')->filter()->unique()->values()->all();
            $pagos = $ordenes->flatMap->payments;
            $establecimientos = DB::table('establishment_order')->whereIn('order_id', $ids)->pluck('establishment_id')->unique()->all();

            // Una inscripción viva tiene cupos y usos de código tomados: se devuelven.
            foreach ($ordenes as $orden) {
                if (in_array($orden->status, [OrderStatus::Reservada, OrderStatus::Finalizada], true)) {
                    $this->cupos->devolver($orden->consumoDeCupos());
                }
            }

            foreach ($pagos as $pago) {
                foreach ($pago->proofs as $comprobante) {
                    $archivos[] = [$comprobante->disk, $comprobante->path];
                }
            }
            foreach ($ordenes->flatMap->invoiceRecords as $factura) {
                if ($factura->path) {
                    $archivos[] = [$factura->disk, $factura->path];
                }
            }

            // Las bitácoras de lo que se va a borrar (la constancia de este borrado queda aparte).
            AuditLog::query()->where(fn ($q) => $q
                ->where(fn ($o) => $o->where('auditable_type', (new Order)->getMorphClass())->whereIn('auditable_id', $ids))
                ->orWhere(fn ($p) => $p->where('auditable_type', (new Payment)->getMorphClass())->whereIn('auditable_id', $pagos->pluck('id')))
                ->orWhere(fn ($p) => $p->where('auditable_type', (new Participant)->getMorphClass())->whereIn('auditable_id', Participant::whereIn('order_id', $ids)->pluck('id')))
            )->delete();

            MagicLink::query()->where(fn ($q) => $q->whereIn('order_id', $ids)->orWhereIn('order_group_id', $grupos))->delete();
            Invitation::query()->where('event_id', $solicitud->event_id)->where('email', mb_strtolower(trim($solicitud->email)))->delete();

            // Participantes, pagos, comprobantes, facturas, credenciales y acreditaciones caen en cascada.
            Order::query()->whereIn('id', $ids)->delete();

            // Los códigos que usaban vuelven a contar sus personas.
            DiscountCode::query()->whereIn('id', $ordenes->pluck('discount_code_id')->filter()->unique())->get()->each->recalcularUsos();
            OrderGroup::query()->whereIn('id', $grupos)->whereDoesntHave('orders')->delete();
            // Entidades pagadoras que nadie más usa (cada inscripción de prueba crea las suyas).
            PayerEntity::query()->whereIn('id', $pagadores)->whereDoesntHave('orders')->delete();
            Establishment::query()->whereIn('id', $establecimientos)
                ->whereNotIn('id', DB::table('establishment_order')->select('establishment_id'))
                ->delete();

            Auditor::registrar(
                sobre: $solicitud->event,
                accion: 'solicitud.eliminada_con_todo',
                comentario: "Se eliminó la solicitud de {$solicitud->email} y todo lo relacionado",
                propiedades: ['correo' => $solicitud->email, 'inscripciones' => count($ids), 'folios' => $folios],
            );

            $solicitud->delete();

            return ['inscripciones' => count($ids), 'folios' => $folios];
        });

        // Los archivos se borran solo si la base ya confirmó: un archivo suelto no rompe nada, uno faltante sí.
        foreach ($archivos as [$disco, $ruta]) {
            Storage::disk($disco)->delete($ruta);
        }

        return $resultado;
    }
}
