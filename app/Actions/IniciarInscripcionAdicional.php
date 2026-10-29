<?php

namespace App\Actions;

use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Exceptions\InscripcionAdicionalNoPermitida;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Support\Auditor;
use Illuminate\Support\Facades\DB;

/**
 * Sumar gente a un colegio que ya confirmó o pagó, sin tocar lo ya pagado: se
 * crea una inscripción NUEVA (borrador) del mismo colegio y entidad pagadora,
 * sin participantes, que se confirma con el flujo de siempre (su propio folio,
 * cupos, precio congelado y pago). La original no cambia en nada.
 */
class IniciarInscripcionAdicional
{
    /** @throws InscripcionAdicionalNoPermitida */
    public function __invoke(Order $origen): Order
    {
        return DB::transaction(function () use ($origen): Order {
            // Se recarga con candado: dos clics seguidos no crean dos borradores.
            $origen = Order::query()->with(['event', 'establishments', 'group'])->whereKey($origen->getKey())->lockForUpdate()->firstOrFail();

            $this->verificar($origen);

            $existente = Order::query()
                ->where('parent_order_id', $origen->getKey())
                ->where('status', OrderStatus::Borrador)
                ->first();

            if ($existente !== null) {
                return $existente;
            }

            $grupo = $origen->group ?? OrderGroup::paraResponsable($origen->event, $origen->responsible_email);

            if ($origen->group_id === null) {
                $origen->forceFill(['group_id' => $grupo->getKey()])->save();
            }

            $nueva = Order::create([
                'event_id' => $origen->event_id,
                'group_id' => $grupo->getKey(),
                'parent_order_id' => $origen->getKey(),
                'kind' => $origen->kind,
                'created_by_user_id' => $origen->created_by_user_id,
                'notify_client' => $origen->notify_client,
                'responsible_name' => $origen->responsible_name,
                'responsible_lastname' => $origen->responsible_lastname,
                'responsible_email' => $origen->responsible_email,
                'responsible_position' => $origen->responsible_position,
                'responsible_phone' => $origen->responsible_phone,
                'responsible_institution' => $origen->responsible_institution,
                // El mismo registro, no una copia: editarlo cambiaría la factura de la original.
                'payer_entity_id' => $origen->payer_entity_id,
                // Hereda el código: es la misma persona ampliando su compra.
                'discount_code_id' => $origen->discount_code_id,
                'discount_code_email' => $origen->discount_code_email,
            ]);
            $nueva->establishments()->sync($origen->establishments->modelKeys());

            Auditor::registrar(
                sobre: $nueva,
                accion: 'orden.adicional_creada',
                comentario: "Inscripción adicional de {$origen->number}",
                propiedades: ['origen' => $origen->number],
                actorLabel: $origen->responsible_email,
            );

            return $nueva;
        });
    }

    /**
     * "Agregar otro establecimiento": un borrador NUEVO dentro del mismo conjunto
     * para otro colegio. Hereda al responsable y el código de descuento, pero no
     * el colegio ni la entidad pagadora: los completa el cliente. Sin
     * `parent_order_id`, porque a diferencia de la adicional del mismo colegio
     * aquí sí se editan establecimiento y pagador.
     *
     * @throws InscripcionAdicionalNoPermitida
     */
    public function otroEstablecimiento(Order $origen): Order
    {
        return DB::transaction(function () use ($origen): Order {
            $origen = Order::query()->with(['event', 'group'])->whereKey($origen->getKey())->lockForUpdate()->firstOrFail();

            $this->verificar($origen);

            $grupo = $origen->group ?? OrderGroup::paraResponsable($origen->event, $origen->responsible_email);

            if ($origen->group_id === null) {
                $origen->forceFill(['group_id' => $grupo->getKey()])->save();
            }

            $existente = Order::query()
                ->where('group_id', $grupo->getKey())
                ->where('status', OrderStatus::Borrador)
                ->whereNull('parent_order_id')
                ->whereKeyNot($origen->getKey())
                ->first();

            if ($existente !== null) {
                return $existente;
            }

            $nueva = Order::create([
                'event_id' => $origen->event_id,
                'group_id' => $grupo->getKey(),
                'kind' => $origen->kind,
                'created_by_user_id' => $origen->created_by_user_id,
                'notify_client' => $origen->notify_client,
                'responsible_name' => $origen->responsible_name,
                'responsible_lastname' => $origen->responsible_lastname,
                'responsible_email' => $origen->responsible_email,
                'responsible_position' => $origen->responsible_position,
                'responsible_phone' => $origen->responsible_phone,
                // Hereda el código: es la misma persona ampliando su compra.
                'discount_code_id' => $origen->discount_code_id,
                'discount_code_email' => $origen->discount_code_email,
            ]);

            Auditor::registrar(
                sobre: $nueva,
                accion: 'orden.otro_establecimiento_creada',
                comentario: "Otro establecimiento a partir de {$origen->number}",
                propiedades: ['origen' => $origen->number],
                actorLabel: $origen->responsible_email,
            );

            return $nueva;
        });
    }

    /** @throws InscripcionAdicionalNoPermitida */
    private function verificar(Order $origen): void
    {
        if (! in_array($origen->status, [OrderStatus::Reservada, OrderStatus::Finalizada], true)) {
            throw InscripcionAdicionalNoPermitida::porque('Solo se pueden sumar personas a una inscripción confirmada o pagada.');
        }

        if ($origen->kind === OrderKind::Invitado) {
            throw InscripcionAdicionalNoPermitida::porque('Un invitado no inscribe a más personas.');
        }

        if (! $origen->event->admiteInscripciones()) {
            throw InscripcionAdicionalNoPermitida::porque('Este evento ya no recibe inscripciones.');
        }
    }
}
