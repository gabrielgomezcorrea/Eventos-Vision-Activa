<?php

namespace App\Console\Commands;

use App\Enums\EventStatus;
use App\Enums\OrderKind;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Ticket;
use App\Support\EnvioDeCredenciales;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Catches up credentials that never went out (09/11/2026): paid orders
 * created with client emails off never sent "payment approved", and before
 * that day participants never got their own. Only events that are not closed.
 * Run with --dry-run first.
 */
class EnviarCredencialesPendientes extends Command
{
    protected $signature = 'credenciales:enviar-pendientes
        {--evento= : Solo este evento (slug)}
        {--dry-run : Muestra qué se enviaría sin enviar nada}';

    protected $description = 'Envía las credenciales de inscripciones pagadas que nunca salieron';

    public function handle(): int
    {
        $eventos = fn (Builder $q) => $q->where('status', '!=', EventStatus::Cerrado->value)
            ->when($this->option('evento'), fn (Builder $e, string $slug) => $e->where('slug', $slug));

        // Responsable: las pagadas con correos al cliente apagados nunca recibieron "pago aprobado".
        $ordenes = Order::query()
            ->whereHas('event', $eventos)
            ->where('payment_status', PaymentStatus::Aprobado->value)
            ->where('kind', '!=', OrderKind::Invitado->value)
            ->where('notify_client', false)
            ->whereNull('credentials_sent_at')
            ->with('event')
            ->get();

        $tickets = Ticket::query()
            ->vigentes()
            ->whereNull('emailed_at')
            ->whereHas('event', $eventos)
            ->whereHas('order', fn (Builder $q) => $q->where('payment_status', PaymentStatus::Aprobado->value))
            ->with('participant', 'event', 'order')
            ->get()
            ->filter(fn (Ticket $t): bool => (bool) filter_var((string) $t->participant?->email, FILTER_VALIDATE_EMAIL))
            ->values();

        $this->info("Responsables: {$ordenes->count()} · Participantes: {$tickets->count()}");

        foreach ($ordenes as $orden) {
            $this->line("  Responsable {$orden->number} ({$orden->event->name}): {$orden->responsible_email}");
        }

        foreach ($tickets as $ticket) {
            $this->line("  Participante {$ticket->order->number}: {$ticket->participant->email}");
        }

        if ($this->option('dry-run')) {
            $this->warn('Simulación: no se envió nada.');

            return self::SUCCESS;
        }

        $ordenes->each(fn (Order $orden) => EnvioDeCredenciales::alResponsable($orden));
        EnvioDeCredenciales::aParticipantes($tickets);

        $this->info('Enviados a la cola de correos.');

        return self::SUCCESS;
    }
}
