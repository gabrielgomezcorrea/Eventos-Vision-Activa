<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Exceptions\CuposInsuficientes;
use App\Exceptions\ReactivacionNoPermitida;
use App\Models\DiscountCode;
use App\Models\Order;
use App\Support\Auditor;
use Illuminate\Support\Facades\DB;

/**
 * Revive una reserva vencida, típicamente porque el cliente pagó tarde.
 *
 * Vuelve a tomar los cupos con el mismo UPDATE condicional de la confirmación:
 * si mientras tanto otro cliente ocupó la vacante, falla y la orden sigue
 * vencida.
 *
 * El precio se recalcula al valor vigente. Si la reserva venció, perdió la
 * silla: volver a tomarla es un trato nuevo, y mantener un precio anticipado
 * que ya expiró sería premiar al que no pagó a tiempo por sobre el que sí.
 */
class ReactivarReserva
{
    public function __construct(private readonly ReservarCupos $cupos) {}

    /** Por qué no se puede reactivar, o null si se puede intentar. */
    public static function impedimento(Order $orden): ?string
    {
        return match (true) {
            $orden->status !== OrderStatus::Vencida => 'Solo se reactiva una reserva vencida.',
            ! $orden->event->admiteInscripciones() => 'El evento ya no admite inscripciones.',
            default => null,
        };
    }

    /**
     * @throws ReactivacionNoPermitida
     * @throws CuposInsuficientes
     */
    public function __invoke(Order $orden): Order
    {
        return DB::transaction(function () use ($orden): Order {
            $fresca = Order::whereKey($orden->getKey())->lockForUpdate()->firstOrFail();
            $fresca->load('event', 'participantesVigentes.accessType.sessions');

            if (($impedimento = self::impedimento($fresca)) !== null) {
                throw ReactivacionNoPermitida::porque($impedimento);
            }

            $this->cupos->tomar($fresca->consumoDeCupos());

            // La orden ya tenía su código: no importa que haya vencido, pero sí que quepa.
            $codigo = $fresca->discount_code_id !== null ? DiscountCode::find($fresca->discount_code_id) : null;

            if ($codigo !== null && ! $codigo->tomarUsos($fresca->participantesVigentes->count(), ignorarVigencia: true)) {
                throw ReactivacionNoPermitida::porque('El código de descuento de esta inscripción ya no tiene usos disponibles.');
            }

            $totalAnterior = (int) $fresca->total;
            $anticipado = false;
            $rigeHasta = null;

            foreach ($fresca->participantesVigentes as $participante) {
                $acceso = $participante->accessType;

                if ($acceso?->tieneDescuentoAnticipado()) {
                    $anticipado = true;
                    $rigeHasta = $acceso->early_until;
                }

                $participante->forceFill([
                    'unit_price' => (int) ($acceso?->precioVigente() ?? $participante->unit_price),
                ])->save();
            }

            $fresca->refresh()->load('participantesVigentes.accessType.sessions', 'event.discountTiers');

            $subtotal = (int) $fresca->participantesVigentes->sum(fn ($p) => $p->unit_price);
            $tramo = $codigo === null ? $fresca->event->tramoDeDescuento($fresca->participantesVigentes->count()) : null;
            $descuento = $codigo?->calcular($subtotal) ?? $tramo?->calcular($subtotal) ?? 0;

            $fresca->forceFill([
                'status' => OrderStatus::Reservada,
                'subtotal' => $subtotal,
                'discount_amount' => $descuento,
                'discount_label' => $codigo !== null
                    ? 'Código '.$codigo->formateado().' ('.$codigo->etiqueta().')'
                    : $tramo?->etiqueta(),
                'total' => $subtotal - $descuento,
                'applied_tariff' => $anticipado ? 'anticipado' : 'normal',
                'applied_tariff_until' => $anticipado ? $rigeHasta : null,
                'reserved_until' => $fresca->event->calcularVencimientoReserva(),
            ])->save();

            Auditor::registrar(
                sobre: $fresca,
                accion: 'orden.reactivada',
                estadoAnterior: OrderStatus::Vencida->value,
                estadoNuevo: OrderStatus::Reservada->value,
                propiedades: [
                    'vence' => $fresca->reserved_until?->format('d-m-Y H:i'),
                    'total_anterior' => $totalAnterior,
                    'total' => (int) $fresca->total,
                ],
            );

            return $fresca;
        });
    }
}
