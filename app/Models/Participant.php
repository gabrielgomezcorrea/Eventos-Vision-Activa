<?php

namespace App\Models;

use App\Enums\ParticipantStatus;
use Database\Factories\ParticipantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property ParticipantStatus $status
 * @property int $unit_price
 * @property Carbon|null $replaced_at
 */
class Participant extends Model
{
    /** @use HasFactory<ParticipantFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => ParticipantStatus::class,
            'unit_price' => 'integer',
            'replaced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Establishment, $this> */
    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    /** @return BelongsTo<AccessType, $this> */
    public function accessType(): BelongsTo
    {
        return $this->belongsTo(AccessType::class);
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function ticketVigente(): ?Ticket
    {
        return $this->tickets()->vigentes()->first();
    }

    /** @return HasOne<Accreditation, $this> */
    public function accreditation(): HasOne
    {
        return $this->hasOne(Accreditation::class);
    }

    /** @return BelongsTo<self, $this> */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }

    public function getNombreCompletoAttribute(): string
    {
        return trim($this->first_name.' '.($this->last_name ?? ''));
    }
}
