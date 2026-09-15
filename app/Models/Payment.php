<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Pago declarado sobre una orden.
 *
 * El MVP no admite pagos parciales: un pago cubre el total. Sí puede haber
 * varios registros cuando un comprobante es observado y el cliente envía otro;
 * el vigente es el más reciente y los anteriores quedan como historial.
 *
 * @property PaymentMethod $method
 * @property PaymentStatus $status
 * @property int $amount
 * @property Carbon|null $paid_on
 * @property Carbon|null $reviewed_at
 */
class Payment extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'amount' => 'integer',
            'paid_on' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasMany<PaymentProof, $this> */
    public function proofs(): HasMany
    {
        return $this->hasMany(PaymentProof::class);
    }

    /** @return HasMany<PaymentReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(PaymentReview::class)->latest('id');
    }

    /** @return BelongsTo<User, $this> */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    /**
     * Pagos que Contabilidad todavía no resolvió.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePendientesDeRevision(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::EnValidacion);
    }

    public function esperaRevision(): bool
    {
        return $this->status === PaymentStatus::EnValidacion;
    }

    /** El monto declarado no coincide con el total de la orden. */
    public function tieneDiferenciaDeMonto(): bool
    {
        return $this->amount !== (int) $this->order->total;
    }

    public function diferencia(): int
    {
        return $this->amount - (int) $this->order->total;
    }
}
