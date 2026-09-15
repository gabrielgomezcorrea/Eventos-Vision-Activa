<?php

namespace App\Actions;

use App\Enums\PaymentReviewAction;
use App\Enums\PaymentStatus;
use App\Exceptions\RevisionNoValida;
use App\Exceptions\TicketsNoLiberables;
use App\Mail\PagoAprobado;
use App\Mail\PagoObservado;
use App\Mail\PagoRechazado;
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

            $orden = $pago->order;
            $anterior = $orden->payment_status;

            // Solo se toca payment_status. El ciclo de vida de la orden no
            // depende de la decisión contable.
            $orden->forceFill(['payment_status' => $nuevoEstado])->save();

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

        // Con el pago aprobado se liberan las credenciales. Se emiten antes
        // del correo para que el enlace ya exista cuando el cliente lo abra.
        if ($nuevoEstado === PaymentStatus::Aprobado) {
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

        Mail::to($orden->responsible_email)->queue(match ($nuevoEstado) {
            PaymentStatus::Aprobado => new PagoAprobado($orden),
            PaymentStatus::Observado => new PagoObservado($orden, $comentario ?? ''),
            default => new PagoRechazado($orden, $comentario ?? ''),
        });

        return $pago->fresh();
    }
}
