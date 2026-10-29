<?php

namespace App\Actions;

use App\Enums\NivelDeCambio;
use App\Enums\TipoDescuento;
use App\Exceptions\CambioNoPermitido;
use App\Exceptions\CuposInsuficientes;
use App\Models\AccessType;
use App\Models\DiscountCode;
use App\Models\Order;
use App\Models\Participant;
use App\Models\PayerEntity;
use App\Support\Auditor;
use Illuminate\Support\Facades\DB;

/**
 * Cambios que el propio cliente hace sobre una inscripción ya confirmada.
 *
 * Toda operación corre en una transacción, sobre la orden bloqueada y con el
 * nivel de cambio revalidado dentro: lo que se pudo hacer hace un minuto puede
 * no valer ahora (llegó un abono, pasó el límite). Los cupos y los usos del
 * código se toman con UPDATE condicional, igual que al confirmar; si algo falla
 * a mitad de camino, la transacción devuelve todo.
 *
 * El precio de quien no se toca nunca se recalcula: cada participante conserva
 * su `unit_price`. Lo único que se recalcula es el descuento y el total.
 */
class ModificarInscripcion
{
    public function __construct(private readonly ReservarCupos $cupos) {}

    /**
     * @param  array<string, mixed>  $datos  Ya validados y normalizados.
     *
     * @throws CambioNoPermitido
     * @throws CuposInsuficientes
     */
    public function agregarParticipante(Order $orden, array $datos, ?AccessType $acceso): Participant
    {
        return $this->enTransaccion($orden, NivelDeCambio::Completo, function (Order $fresca) use ($datos, $acceso): Participant {
            // Sin acceso (queda en "-") no ocupa cupo ni cobra hasta que se elija uno.
            $acceso?->loadMissing('sessions');
            $this->cupos->tomar($acceso?->consumoDeCupos() ?? []);

            // El cupo del código es por persona (el solicitante), no por participante:
            // sumar a alguien no ocupa otro.
            $codigo = $this->codigoDe($fresca);

            $participante = $fresca->participants()->create($datos + [
                'access_type_id' => $acceso?->getKey(),
                // El precio vigente en este momento, solo para quien entra.
                'unit_price' => $acceso?->precioVigente() ?? 0,
            ]);

            // Cada persona usa un solo código por evento, también al agregarla después.
            if ($codigo !== null && $fresca->participantesConCodigoPrevio()->contains('id', $participante->getKey())) {
                throw CambioNoPermitido::porque(DiscountCode::mensajePorCodigoPrevio(
                    $fresca->participantesConCodigoPrevio()->where('id', $participante->getKey())
                ));
            }

            $this->recalcular($fresca);

            Auditor::registrar(
                sobre: $fresca,
                accion: 'orden.participante_agregado_por_cliente',
                propiedades: ['participante' => $participante->nombre_completo, 'acceso' => $acceso?->name, 'total' => $fresca->total],
                actorLabel: $fresca->responsible_email,
            );

            return $participante;
        });
    }

    /**
     * @throws CambioNoPermitido
     */
    public function quitarParticipante(Order $orden, Participant $participante): void
    {
        $this->enTransaccion($orden, NivelDeCambio::Completo, function (Order $fresca) use ($participante): void {
            $propio = $fresca->participantesVigentes()->with('accessType.sessions')->find($participante->getKey());

            if ($propio === null) {
                throw CambioNoPermitido::porque('Esa persona no está en tu inscripción.');
            }

            if ($fresca->participantesVigentes()->count() <= 1) {
                throw CambioNoPermitido::porque('La inscripción necesita al menos un participante. Si quieres anularla, escríbenos.');
            }

            $this->cupos->devolver($propio->accessType?->consumoDeCupos() ?? []);

            $nombre = $propio->nombre_completo;
            $propio->delete();

            $this->recalcular($fresca);

            Auditor::registrar(
                sobre: $fresca,
                accion: 'orden.participante_quitado_por_cliente',
                propiedades: ['participante' => $nombre, 'total' => $fresca->total],
                actorLabel: $fresca->responsible_email,
            );
        });
    }

    /**
     * Cambia el acceso de uno: devuelve los cupos del viejo y toma los del
     * nuevo en la misma transacción. Sin cupo en el nuevo queda el viejo intacto.
     *
     * @throws CambioNoPermitido
     * @throws CuposInsuficientes
     */
    public function cambiarAcceso(Order $orden, Participant $participante, AccessType $nuevo): void
    {
        $this->enTransaccion($orden, NivelDeCambio::Completo, function (Order $fresca) use ($participante, $nuevo): void {
            $propio = $fresca->participantesVigentes()->with('accessType.sessions')->find($participante->getKey());

            if ($propio === null) {
                throw CambioNoPermitido::porque('Esa persona no está en tu inscripción.');
            }

            if ($propio->access_type_id === $nuevo->getKey()) {
                return;
            }

            $nuevo->loadMissing('sessions');
            $anterior = $propio->accessType;

            // Primero se toma el nuevo: si no hay cupo, la excepción corta antes
            // de devolver nada.
            $this->cupos->tomar($nuevo->consumoDeCupos());
            $this->cupos->devolver($anterior?->consumoDeCupos() ?? []);

            $propio->forceFill([
                'access_type_id' => $nuevo->getKey(),
                'unit_price' => $nuevo->precioVigente(),
            ])->save();

            $this->recalcular($fresca);

            Auditor::registrar(
                sobre: $fresca,
                accion: 'orden.acceso_cambiado_por_cliente',
                propiedades: [
                    'participante' => $propio->nombre_completo,
                    'antes' => $anterior?->name,
                    'despues' => $nuevo->name,
                    'total' => $fresca->total,
                ],
                actorLabel: $fresca->responsible_email,
            );
        });
    }

    /**
     * Corrige datos de contacto sin tocar acceso, cupos ni monto.
     *
     * @param  array<string, mixed>  $datos
     */
    public function corregirParticipante(Order $orden, Participant $participante, array $datos): void
    {
        $this->enTransaccion($orden, NivelDeCambio::SoloDatos, function (Order $fresca) use ($participante, $datos): void {
            $propio = $fresca->participantesVigentes()->find($participante->getKey());

            if ($propio === null) {
                throw CambioNoPermitido::porque('Esa persona no está en tu inscripción.');
            }

            $propio->fill($datos);
            $antes = array_intersect_key($propio->getOriginal(), $propio->getDirty());
            $despues = $propio->getDirty();

            if ($despues === []) {
                return;
            }

            $propio->save();

            Auditor::registrar(
                sobre: $propio,
                accion: 'participante.corregido_por_cliente',
                propiedades: ['antes' => $antes, 'despues' => $despues],
                actorLabel: $fresca->responsible_email,
            );
        });
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function corregirResponsable(Order $orden, array $datos): void
    {
        $this->enTransaccion($orden, NivelDeCambio::SoloDatos, function (Order $fresca) use ($datos): void {
            $fresca->fill($datos);
            $antes = array_intersect_key($fresca->getOriginal(), $fresca->getDirty());
            $despues = $fresca->getDirty();

            if ($despues !== []) {
                $fresca->save();

                Auditor::registrar(
                    sobre: $fresca,
                    accion: 'orden.responsable_corregido_por_cliente',
                    propiedades: ['antes' => $antes, 'despues' => $despues],
                    actorLabel: $fresca->responsible_email,
                );
            }
        });
    }

    /**
     * Solo con el nivel completo: con un abono hecho la factura ya apunta a
     * esta entidad.
     *
     * @param  array<string, mixed>  $datos
     */
    public function actualizarPagador(Order $orden, array $datos): void
    {
        $this->enTransaccion($orden, NivelDeCambio::Completo, function (Order $fresca) use ($datos): void {
            $pagador = $fresca->payerEntity;
            $antes = $pagador?->only(array_keys($datos)) ?? [];

            if ($pagador !== null) {
                $pagador->update($datos);
            } else {
                $fresca->update(['payer_entity_id' => PayerEntity::create($datos)->getKey()]);
            }

            Auditor::registrar(
                sobre: $fresca,
                accion: 'orden.pagador_actualizado_por_cliente',
                propiedades: ['antes' => $antes, 'despues' => $datos],
                actorLabel: $fresca->responsible_email,
            );
        });
    }

    /**
     * Descuento y total con la misma regla de la confirmación: el código si la
     * orden lo tiene (por colegio, igual que al confirmar), si no el tramo por
     * cantidad. Se guarda: el total sigue saliendo de lo que está guardado.
     */
    private function recalcular(Order $orden): void
    {
        $orden->unsetRelation('participantesVigentes');
        $orden->load('event.discountTiers', 'participantesVigentes');

        if ($orden->event->cuentaDescuentoPorConjunto() && $orden->group_id !== null) {
            $orden->load('group.orders.participantesVigentes');
        }

        $subtotal = (int) $orden->participantesVigentes->sum(fn (Participant $p): int => (int) $p->unit_price);
        $codigo = $this->codigoDe($orden);

        if ($codigo !== null) {
            // Porcentaje: sobre el nuevo subtotal. Monto fijo: conserva la parte que
            // le tocó al confirmar (el recorrido ya usó su monto), sin pasar del subtotal.
            $descuento = $codigo->type === TipoDescuento::Porcentaje
                ? $codigo->calcular($subtotal)
                : min((int) $orden->discount_amount, $subtotal);
            $etiqueta = 'Código '.$codigo->formateado().' ('.$codigo->etiqueta().')';
        } else {
            $tramo = $orden->event->tramoDeDescuento($orden->participantesParaDescuento());
            $descuento = $tramo?->calcular($subtotal) ?? 0;
            $etiqueta = $tramo?->etiqueta();
        }

        $orden->forceFill([
            'subtotal' => $subtotal,
            'discount_amount' => $descuento,
            'discount_label' => $descuento > 0 ? $etiqueta : null,
            'total' => $subtotal - $descuento,
        ])->save();
    }

    private function codigoDe(Order $orden): ?DiscountCode
    {
        return $orden->discount_code_id !== null ? DiscountCode::find($orden->discount_code_id) : null;
    }

    /**
     * @template T
     *
     * @param  callable(Order): T  $operacion
     * @return T
     *
     * @throws CambioNoPermitido
     */
    private function enTransaccion(Order $orden, NivelDeCambio $minimo, callable $operacion): mixed
    {
        return DB::transaction(function () use ($orden, $minimo, $operacion): mixed {
            $fresca = Order::whereKey($orden->getKey())->lockForUpdate()->firstOrFail();
            $nivel = $fresca->nivelDeCambioDelCliente();

            $permitido = $minimo === NivelDeCambio::Completo
                ? $nivel->permiteCambiarLaInscripcion()
                : $nivel->permiteCorregirDatos();

            if (! $permitido) {
                throw CambioNoPermitido::porque(match (true) {
                    $nivel === NivelDeCambio::SoloDatos => 'Ya enviaste un pago: para cambiar participantes escríbenos.',
                    default => 'Esta inscripción ya no se puede cambiar desde aquí. Escríbenos.',
                });
            }

            return $operacion($fresca);
        });
    }
}
