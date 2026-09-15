<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\ParticipantStatus;
use App\Enums\PaymentStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Orden de inscripción. Es la entidad central: conecta responsable, entidad
 * pagadora, establecimientos, participantes, accesos, pago y tickets.
 *
 * @property OrderStatus $status
 * @property PaymentStatus $payment_status
 * @property OrderKind $kind
 * @property int $total
 * @property Carbon|null $applied_tariff_until
 * @property int $subtotal
 * @property int $discount_amount
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $reserved_until
 * @property Carbon|null $reminder_sent_at
 * @property Carbon|null $cancelled_at
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * Valores por defecto en memoria. Sin esto, una orden recién creada tiene
     * `status` nulo hasta refrescarla desde la base, y cualquier comparación
     * contra el enum falla en silencio.
     */
    protected $attributes = [
        'status' => 'draft',
        'payment_status' => 'pending',
        'kind' => 'institutional',
        'total' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'kind' => OrderKind::class,
            'total' => 'integer',
            'applied_tariff_until' => 'date',
            'subtotal' => 'integer',
            'discount_amount' => 'integer',
            'confirmed_at' => 'datetime',
            'reserved_until' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<PayerEntity, $this> */
    public function payerEntity(): BelongsTo
    {
        return $this->belongsTo(PayerEntity::class);
    }

    /** @return BelongsToMany<Establishment, $this> */
    public function establishments(): BelongsToMany
    {
        return $this->belongsToMany(Establishment::class);
    }

    /** @return HasMany<Participant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class);
    }

    /**
     * Participantes que ocupan cupo y reciben credencial.
     *
     * @return HasMany<Participant, $this>
     */
    public function participantesVigentes(): HasMany
    {
        return $this->participants()->where('status', '!=', ParticipantStatus::Reemplazado->value);
    }

    /** @return HasMany<MagicLink, $this> */
    public function magicLinks(): HasMany
    {
        return $this->hasMany(MagicLink::class);
    }

    /** @return HasMany<InvoiceRecord, $this> */
    public function invoiceRecords(): HasMany
    {
        return $this->hasMany(InvoiceRecord::class)->latest('issued_on');
    }

    public function estadoDeFactura(): InvoiceStatus
    {
        return $this->invoiceRecords()->exists()
            ? InvoiceStatus::Emitida
            : InvoiceStatus::Pendiente;
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest('id');
    }

    /**
     * Pago vigente: el último declarado. Los anteriores quedan como historial
     * cuando un comprobante fue observado y el cliente envió otro.
     */
    public function pagoVigente(): ?Payment
    {
        return $this->payments()->first();
    }

    /** El cliente puede declarar un pago solo mientras la reserva está viva. */
    public function admiteComprobante(): bool
    {
        if ($this->status !== OrderStatus::Reservada) {
            return false;
        }

        return ! in_array($this->payment_status, [
            PaymentStatus::EnValidacion,
            PaymentStatus::Aprobado,
        ], true);
    }

    public function esBorrador(): bool
    {
        return $this->status === OrderStatus::Borrador;
    }

    public function puedeEditarlaElCliente(): bool
    {
        return $this->status->esEditablePorElCliente();
    }

    /**
     * Total calculado desde los participantes vigentes.
     *
     * Mientras la orden es borrador se calcula con el precio actual del acceso.
     * Una vez confirmada se usa el precio congelado en cada participante, para
     * que cambiar el valor de un acceso no altere órdenes ya emitidas.
     */
    public function calcularTotal(): int
    {
        return $this->calcularSubtotal() - $this->calcularDescuento();
    }

    /** Suma de los accesos, antes del descuento por cantidad. */
    public function calcularSubtotal(): int
    {
        return (int) $this->participantesVigentes
            ->sum(fn (Participant $p) => $this->esBorrador()
                ? ($p->accessType?->precioVigente() ?? 0)
                : $p->unit_price);
    }

    /**
     * Descuento que corresponde hoy.
     *
     * Mientras es borrador se recalcula con cada participante que entra o sale,
     * para que el resumen muestre lo que de verdad va a pagar. Al confirmar se
     * congela y ya no se mueve, ni siquiera con un reemplazo.
     */
    public function calcularDescuento(): int
    {
        if (! $this->esBorrador()) {
            return (int) $this->discount_amount;
        }

        return $this->tramoDeDescuento()?->calcular($this->calcularSubtotal()) ?? 0;
    }

    /** Tramo de descuento aplicable a esta orden, o null. */
    public function tramoDeDescuento(): ?DiscountTier
    {
        return $this->event?->tramoDeDescuento($this->participantesVigentes->count());
    }

    /**
     * Cupos que la orden consume por jornada: [event_session_id => cantidad].
     *
     * @return array<int, int>
     */
    public function consumoDeCupos(): array
    {
        $consumo = [];

        foreach ($this->participantesVigentes as $participante) {
            foreach ($participante->accessType?->consumoDeCupos() ?? [] as $sessionId => $seats) {
                $consumo[$sessionId] = ($consumo[$sessionId] ?? 0) + $seats;
            }
        }

        return $consumo;
    }

    /** La reserva no debe vencer mientras Contabilidad revisa el comprobante. */
    public function reservaEstaVencida(): bool
    {
        if ($this->status !== OrderStatus::Reservada || $this->reserved_until === null) {
            return false;
        }

        if ($this->payment_status->congelaElVencimiento()) {
            return false;
        }

        return $this->reserved_until->isPast();
    }
}
