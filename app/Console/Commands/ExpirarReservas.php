<?php

namespace App\Console\Commands;

use App\Actions\ReservarCupos;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Mail\ReservaVencida;
use App\Models\Order;
use App\Support\Auditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Vence las reservas cuyo plazo pasó y devuelve sus cupos.
 *
 * Es la única vía por la que una reserva vence: nunca debe hacerse desde una
 * vista o un request, porque entonces el vencimiento dependería de que alguien
 * visite una página.
 */
class ExpirarReservas extends Command
{
    protected $signature = 'reservas:expirar {--dry-run : Muestra qué órdenes vencerían sin modificarlas}';

    protected $description = 'Vence las reservas de cupo cuyo plazo expiró y devuelve los cupos';

    public function handle(ReservarCupos $cupos): int
    {
        $ordenes = Order::query()
            ->where('status', OrderStatus::Reservada)
            ->whereNotNull('reserved_until')
            ->where('reserved_until', '<', now())
            // Si el comprobante está en validación o el pago aprobado, la
            // reserva no vence: el cliente ya cumplió su parte.
            ->whereNotIn('payment_status', [
                PaymentStatus::EnValidacion->value,
                PaymentStatus::Aprobado->value,
            ])
            // Con un abono aprobado o esperando revisión la reserva se mantiene.
            ->whereDoesntHave('payments', fn ($q) => $q->whereIn('status', [
                PaymentStatus::Aprobado->value,
                PaymentStatus::EnValidacion->value,
            ]))
            ->with('participants.accessType.sessions', 'event')
            ->get();

        if ($ordenes->isEmpty()) {
            $this->info('No hay reservas vencidas.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach ($ordenes as $orden) {
                $this->line("{$orden->number} · venció {$orden->reserved_until->diffForHumans()} · {$orden->participantesVigentes->count()} participante(s)");
            }

            $this->info($ordenes->count().' orden(es) vencerían.');

            return self::SUCCESS;
        }

        $vencidas = 0;

        foreach ($ordenes as $orden) {
            DB::transaction(function () use ($orden, $cupos, &$vencidas): void {
                $fresca = Order::whereKey($orden->getKey())->lockForUpdate()->firstOrFail();

                // Puede haber cambiado entre la consulta y el bloqueo.
                if (! $fresca->reservaEstaVencida()) {
                    return;
                }

                $cupos->devolver($orden->consumoDeCupos());
                $fresca->discountCode?->devolverUsos($fresca->participantesVigentes->count());

                $fresca->forceFill(['status' => OrderStatus::Vencida])->save();

                Auditor::registrar(
                    sobre: $fresca,
                    accion: 'orden.reserva_vencida',
                    estadoAnterior: OrderStatus::Reservada->value,
                    estadoNuevo: OrderStatus::Vencida->value,
                    comentario: 'Vencimiento automático del plazo de reserva.',
                    actorLabel: 'Sistema',
                );

                $vencidas++;
            });

            if ($orden->fresh()->status === OrderStatus::Vencida) {
                Mail::to($orden->responsible_email)->queue(new ReservaVencida($orden));
            }
        }

        $this->info("{$vencidas} reserva(s) vencidas y cupos liberados.");

        return self::SUCCESS;
    }
}
