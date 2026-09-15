<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Mail\RecordatorioDeReserva;
use App\Models\Order;
use App\Support\Auditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Avisa a quienes tienen una reserva por vencer.
 *
 * Se envía una sola vez por reserva. Vencer una inscripción sin haber avisado
 * antes es la forma más rápida de perder una venta que ya estaba hecha.
 */
class RecordarReservas extends Command
{
    /** Antelación cuando el evento no define la suya. */
    public const HORAS_POR_DEFECTO = 48;

    protected $signature = 'reservas:recordar
        {--horas= : Fuerza la antelación en horas, ignorando la de cada evento}
        {--dry-run : Muestra a quién se avisaría sin enviar nada}';

    protected $description = 'Envía el recordatorio a las reservas próximas a vencer';

    public function handle(): int
    {
        $forzadas = $this->option('horas') !== null
            ? max(1, (int) $this->option('horas'))
            : null;

        // El tope alto se usa solo para traer candidatas; el plazo real de cada
        // evento se comprueba abajo, una por una.
        $ventana = $forzadas ?? 24 * 30;

        $ordenes = Order::query()
            ->where('status', OrderStatus::Reservada)
            ->whereNull('reminder_sent_at')
            ->whereNotNull('reserved_until')
            ->whereBetween('reserved_until', [now(), now()->addHours($ventana)])
            // Si ya cargó el comprobante o el pago está aprobado, no hay nada
            // que recordar: la pelota está del lado de Contabilidad.
            ->whereNotIn('payment_status', [
                PaymentStatus::EnValidacion->value,
                PaymentStatus::Aprobado->value,
            ])
            ->with('event', 'participants')
            ->get()
            ->filter(function (Order $orden) use ($forzadas): bool {
                $horas = $forzadas
                    ?? $orden->event->reminder_hours_before
                    ?? self::HORAS_POR_DEFECTO;

                return $orden->reserved_until->lessThanOrEqualTo(now()->addHours($horas));
            });

        if ($ordenes->isEmpty()) {
            $this->info('No hay reservas por vencer dentro del plazo de aviso de su evento.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach ($ordenes as $orden) {
                $this->line(sprintf(
                    '%s · %s · vence %s · $%s',
                    $orden->number,
                    $orden->responsible_email,
                    $orden->reserved_until->diffForHumans(),
                    number_format($orden->total, 0, ',', '.'),
                ));
            }

            $this->info($ordenes->count().' recordatorio(s) se enviarían.');

            return self::SUCCESS;
        }

        foreach ($ordenes as $orden) {
            Mail::to($orden->responsible_email)->queue(new RecordatorioDeReserva($orden));

            $orden->forceFill(['reminder_sent_at' => now()])->save();

            Auditor::registrar(
                sobre: $orden,
                accion: 'reserva.recordatorio_enviado',
                propiedades: ['vence' => $orden->reserved_until->toDateTimeString()],
                actorLabel: 'Sistema',
            );
        }

        $this->info($ordenes->count().' recordatorio(s) enviados.');

        return self::SUCCESS;
    }
}
