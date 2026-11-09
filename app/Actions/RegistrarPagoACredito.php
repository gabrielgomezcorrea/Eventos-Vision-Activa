<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\ComprobanteNoAceptado;
use App\Exceptions\CuposInsuficientes;
use App\Exceptions\ReactivacionNoPermitida;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Auditor;
use Illuminate\Support\Facades\DB;

/**
 * El cliente elige pagar a crédito: la inscripción hace de orden de compra,
 * no adjunta archivo y puede anotar el número de la suya. Queda como un pago
 * más, por el saldo y en revisión, para que Contabilidad lo apruebe por el
 * mismo camino que un comprobante (credenciales, factura y vencimiento
 * congelado incluidos).
 */
class RegistrarPagoACredito
{
    public function __construct(private readonly ReactivarReserva $reactivar) {}

    /** @throws ComprobanteNoAceptado */
    public function __invoke(Order $orden, ?string $ordenDeCompra, string $actorLabel): Payment
    {
        if (! $orden->admitePagoACredito()) {
            throw $orden->pagado() > 0 && $orden->admiteComprobante()
                ? new ComprobanteNoAceptado('Ya tienes abonos al contado: el saldo se paga de la misma forma.')
                : ComprobanteNoAceptado::para($orden);
        }

        if ($orden->status === OrderStatus::Vencida) {
            try {
                $orden = $this->reactivar->__invoke($orden, conservarPrecio: true);
            } catch (CuposInsuficientes|ReactivacionNoPermitida $e) {
                throw new ComprobanteNoAceptado('Tu reserva venció y ya no se puede retomar: '.lcfirst($e->getMessage()).' Escríbenos para ver otra opción.');
            }
        }

        $ordenDeCompra = trim(preg_replace('/\s+/u', ' ', (string) $ordenDeCompra));

        return DB::transaction(function () use ($orden, $ordenDeCompra, $actorLabel): Payment {
            $pago = $orden->payments()->create([
                'method' => PaymentMethod::Credito,
                'status' => PaymentStatus::EnValidacion,
                'amount' => $orden->saldo(),
                'reference' => $orden->number,
                'purchase_order' => $ordenDeCompra !== '' ? $ordenDeCompra : null,
                'submitted_by_label' => $actorLabel,
            ]);

            $anterior = $orden->payment_status;
            $orden->forceFill(['payment_status' => PaymentStatus::EnValidacion])->save();

            Auditor::registrar(
                sobre: $orden,
                accion: 'pago_a_credito.recibido',
                estadoAnterior: $anterior->value,
                estadoNuevo: PaymentStatus::EnValidacion->value,
                propiedades: ['monto' => $pago->amount, 'orden_de_compra' => $pago->purchase_order],
                actorLabel: $actorLabel,
            );

            return $pago;
        });
    }
}
