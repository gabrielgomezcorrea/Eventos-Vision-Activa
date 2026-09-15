<?php

namespace App\Actions;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\ComprobanteNoAceptado;
use App\Mail\ComprobanteRecibido;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Support\Auditor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Registra un comprobante de transferencia sobre una orden.
 *
 * Lo puede cargar el cliente desde su enlace, o Coordinación en su nombre
 * cuando el comprobante llega por correo: el expediente de la orden debe ser
 * único, no repartido entre el sistema y una bandeja de Gmail.
 */
class RegistrarComprobante
{
    /**
     * @param  array<string, mixed>  $datos  Datos ya validados por su Form Request.
     *
     * @throws ComprobanteNoAceptado
     */
    public function __invoke(
        Order $orden,
        array $datos,
        UploadedFile $archivo,
        ?User $usuario = null,
        ?string $actorLabel = null,
    ): Payment {
        if (! $orden->admiteComprobante()) {
            throw ComprobanteNoAceptado::para($orden);
        }

        $disco = config('filesystems.private_disk');
        $actorLabel ??= $usuario->name ?? $orden->responsible_email;

        // El archivo se guarda antes de la transacción porque escribir en disco
        // no participa del rollback; si la transacción falla, queda un huérfano
        // que no rompe nada.
        $ruta = $archivo->store("comprobantes/{$orden->getKey()}", $disco);

        if ($ruta === false) {
            throw new \RuntimeException('No se pudo guardar el archivo del comprobante.');
        }

        try {
            $pago = DB::transaction(function () use ($orden, $datos, $archivo, $ruta, $disco, $usuario, $actorLabel) {
                $pago = $orden->payments()->create([
                    'method' => PaymentMethod::Transferencia,
                    'status' => PaymentStatus::EnValidacion,
                    'amount' => (int) $datos['amount'],
                    'paid_on' => $datos['paid_on'] ?? null,
                    'bank_name' => $datos['bank_name'] ?? null,
                    'payer_name' => $datos['payer_name'] ?? null,
                    'payer_rut' => $datos['payer_rut'] ?? null,
                    'reference' => $datos['reference'] ?? null,
                    'notes' => $datos['notes'] ?? null,
                    'submitted_by_user_id' => $usuario?->getKey(),
                    'submitted_by_label' => $actorLabel,
                ]);

                $pago->proofs()->create([
                    'disk' => $disco,
                    'path' => $ruta,
                    'original_name' => $archivo->getClientOriginalName(),
                    'mime_type' => $archivo->getClientMimeType(),
                    'size' => $archivo->getSize(),
                    'hash' => hash_file('sha256', $archivo->getRealPath()),
                    'uploaded_by_user_id' => $usuario?->getKey(),
                    'uploaded_by_label' => $actorLabel,
                ]);

                // La orden pasa a "en validación", lo que además congela el
                // vencimiento de la reserva mientras Contabilidad revisa.
                $anterior = $orden->payment_status;
                $orden->forceFill(['payment_status' => PaymentStatus::EnValidacion])->save();

                Auditor::registrar(
                    sobre: $orden,
                    accion: 'comprobante.recibido',
                    estadoAnterior: $anterior->value,
                    estadoNuevo: PaymentStatus::EnValidacion->value,
                    propiedades: ['monto' => $pago->amount, 'archivo' => $archivo->getClientOriginalName()],
                    actorLabel: $actorLabel,
                );

                return $pago;
            });
        } catch (\Throwable $e) {
            // El archivo se escribió fuera de la transacción, así que el
            // rollback no lo borra: hay que limpiarlo a mano.
            Storage::disk($disco)->delete($ruta);

            throw $e;
        }

        Mail::to($orden->responsible_email)->queue(new ComprobanteRecibido($orden->fresh()));

        return $pago;
    }
}
