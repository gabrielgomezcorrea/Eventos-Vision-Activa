<?php

namespace App\Actions;

use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use App\Support\Auditor;
use Illuminate\Support\Facades\DB;

/**
 * Invitado del evento: expositores, autoridades y prensa entran sin pagar.
 *
 * Es una inscripción como cualquier otra, en $0 y ya aprobada: ocupa cupo —si
 * no, la sala se sobrevende— y recibe su credencial para acreditarse en la
 * puerta igual que el resto.
 */
class RegistrarInvitado
{
    public function __construct(
        private readonly ReservarCupos $cupos,
        private readonly GenerarNumeroDeOrden $numero,
        private readonly EmitirTickets $tickets,
    ) {}

    /**
     * @param  array<string, mixed>  $datos  ya validados por su Form Request
     */
    public function __invoke(Event $evento, array $datos, ?User $usuario = null): Order
    {
        $orden = DB::transaction(function () use ($evento, $datos, $usuario): Order {
            $establecimiento = filled($datos['establecimiento'] ?? null)
                ? Establishment::create(['name' => $datos['establecimiento']])
                : null;

            $orden = Order::create([
                'event_id' => $evento->getKey(),
                'kind' => OrderKind::Invitado,
                'status' => OrderStatus::Reservada,
                'payment_status' => PaymentStatus::Aprobado,
                'responsible_name' => $datos['first_name'],
                'responsible_lastname' => $datos['last_name'],
                'responsible_email' => $datos['email'],
                'responsible_phone' => $datos['phone'] ?? null,
                'responsible_position' => $datos['position'] ?? null,
                'responsible_institution' => $establecimiento?->name,
            ]);

            if ($establecimiento) {
                $orden->establishments()->attach($establecimiento);
            }

            $orden->participants()->create([
                'first_name' => $datos['first_name'],
                'last_name' => $datos['last_name'],
                'rut' => $datos['rut'] ?? null,
                'email' => $datos['email'],
                'phone' => $datos['phone'] ?? null,
                'position' => $datos['position'] ?? null,
                'access_type_id' => $datos['access_type_id'],
                'establishment_id' => $establecimiento?->getKey(),
                'unit_price' => 0,
            ]);

            $orden->refresh()->load('participants.accessType.sessions', 'event');

            $this->cupos->tomar($orden->consumoDeCupos());

            $orden->forceFill([
                'number' => ($this->numero)($evento),
                'subtotal' => 0,
                'discount_amount' => 0,
                'total' => 0,
                'confirmed_at' => now(),
            ])->save();

            Auditor::registrar(
                sobre: $orden,
                accion: 'invitado.registrado',
                estadoNuevo: OrderStatus::Reservada->value,
                propiedades: ['numero' => $orden->number, 'invitado' => $orden->responsableNombreCompleto()],
                actorLabel: $usuario?->name ?? 'Invitación por correo',
            );

            return $orden;
        });

        // Su credencial sale de inmediato: no hay pago que esperar.
        ($this->tickets)($orden->fresh());

        return $orden->fresh();
    }
}
