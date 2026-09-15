<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * Jornada del evento. Es la unidad sobre la que se controla el cupo.
 *
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property int|null $capacity
 * @property int $reserved_seats
 * @property int $position
 * @property-read Pivot $pivot  Presente al cargarla desde un acceso (`AccessType::sessions`).
 */
class EventSession extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'capacity' => 'integer',
            'reserved_seats' => 'integer',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsToMany<AccessType, $this> */
    public function accessTypes(): BelongsToMany
    {
        return $this->belongsToMany(AccessType::class)->withPivot('seats');
    }

    /** Capacidad nula significa sin límite. */
    public function tieneCapacidadLimitada(): bool
    {
        return $this->capacity !== null;
    }

    public function cuposDisponibles(): ?int
    {
        if (! $this->tieneCapacidadLimitada()) {
            return null;
        }

        return max(0, $this->capacity - $this->reserved_seats);
    }

    public function puedeReservar(int $cantidad): bool
    {
        if (! $this->tieneCapacidadLimitada()) {
            return true;
        }

        return $this->cuposDisponibles() >= $cantidad;
    }
}
