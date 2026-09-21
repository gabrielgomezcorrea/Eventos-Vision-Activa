<?php

namespace App\Actions;

use App\Enums\PaymentReviewAction;
use App\Enums\PaymentStatus;
use App\Exceptions\RevisionNoValida;
use App\Exceptions\TicketsNoLiberables;
use App\Mail\AbonoRecibido;
use App\Mail\PagoAprobado;
use App\Mail\PagoObservado;
use App\Mail\PagoRechazado;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentReview;
use App\Models\User;
use App\Support\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Decisión de Contabilidad sobre un comprobante: aprobar, observar o rechazar.
 *
 * Cambia la situación financiera de la orden, nunca su ciclo de vida: una
 * observación no cancela ni vence la inscripción, solo devuelve la pelota al
 * cliente.
 */
class RevisarPago
{
    /**
     * @throws RevisionNoValida
     */
    public function __invoke(
        Payment $pago,
        PaymentReviewAction $accion,
        User $usuario,
        ?string $comentario = null,
    ): Payment {
        if (! $pago->esperaRevision()) {
            throw RevisionNoValida::porqueYaFueResuelto($pago);
        }

        if ($accion->exigeComentario() && blank($comentario)) {
            throw RevisionNoValida::porqueFaltaComentario($accion);
        }

        $nuevoEstado = $accion->estadoResultante();

        DB::transaction(function () use ($pago, $accion, $usuario, $comentario, $nuevoEstado): void {
            PaymentReview::create([
                'payment_id' => $pago->getKey(),
                'user_id' => $usuario->getKey(),
                'user_label' => $usuario->name,
                'action' => $accion,
                'comment' => $comentario,
            ]);

            $pago->forceFill([
                'status' => $nuevoEstado,
                'reviewed_at' => now(),
            ])->save();

            $orden = $pago->order->fresh();
            $anterior = $orden->payment_status;

            // Solo se toca payment_status. El ciclo de vida de la orden no
            // depende de la decisión contable. Aprobar un abono no cierra el
            // pago: la inscripción sigue pendiente mientras quede saldo.
            $orden->forceFill(['payment_status' => $this->estadoDeLaOrden($orden, $nuevoEstado)])->save();

            Auditor::registrar(
                sobre: $orden,
                accion: 'pago.'.$accion->value,
                estadoAnterior: $anterior->value,
                estadoNuevo: $nuevoEstado->value,
                comentario: $comentario,
                propiedades: ['pago_id' => $pago->getKey(), 'monto' => $pago->amount],
            );
        });

        $orden = $pago->order->fresh();
        $totalPagado = $orden->payment_status === PaymentStatus::Aprobado;

        // Con el pago aprobado se liberan las credenciales. Se emiten antes
        // del correo para que el enlace ya exista cuando el cliente lo abra.
        if ($totalPagado) {
            try {
                app(EmitirTickets::class)($orden);
                $orden = $orden->fresh();
            } catch (TicketsNoLiberables $e) {
                Log::error('No se pudieron emitir las credenciales tras aprobar el pago.', [
                    'orden' => $orden->number,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Mail::to($orden->responsible_email)->queue(match (true) {
            $totalPagado => new PagoAprobado($orden),
            $nuevoEstado === PaymentStatus::Aprobado => new AbonoRecibido($orden, $pago->fresh()),
            $nuevoEstado === PaymentStatus::Observado => new PagoObservado($orden, $comentario ?? ''),
            default => new PagoRechazado($orden, $comentario ?? ''),
        });

        return $pago->fresh();
    }

    /**
     * Estado de la inscripción después de revisar un abono: solo queda
     * aprobada cuando lo pagado cubre el total.
     */
    private function estadoDeLaOrden(Order $orden, PaymentStatus $revision): PaymentStatus
    {
        if ($revision !== PaymentStatus::Aprobado) {
            return $revision;
        }

        return $orden->saldo() === 0 ? PaymentStatus::Aprobado : PaymentStatus::Pendiente;
    }
}
