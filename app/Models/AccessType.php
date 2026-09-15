<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * Tipo de acceso comprable, ej. "Jornada 1" o "Ambas jornadas".
 * Configurable por evento: nunca fijar estos tipos en el código.
 *
 * @property int $price
 * @property int|null $early_price
 * @property Carbon|null $early_until
 * @property bool $is_active
 * @property int $position
 */
class AccessType extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'early_price' => 'integer',
            'early_until' => 'date',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * Precio que corresponde hoy.
     *
     * El anticipado rige hasta el final de su día: quien paga el mismo 30 de
     * septiembre alcanzó el precio, y explicarle que venció a las 00:00 de ese
     * día es una discusión que nadie quiere tener.
     */
    public function precioVigente(?CarbonInterface $momento = null): int
    {
        return $this->tieneDescuentoAnticipado($momento)
            ? (int) $this->early_price
            : (int) $this->price;
    }

    /** Si en este momento rige el precio anticipado. */
    public function tieneDescuentoAnticipado(?CarbonInterface $momento = null): bool
    {
        if ($this->early_price === null || $this->early_until === null) {
            return false;
        }

        return ($momento ?? now())->lessThanOrEqualTo($this->early_until->endOfDay());
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Jornadas que consume este acceso, con la cantidad de cupos por jornada.
     *
     * @return BelongsToMany<EventSession, $this, Pivot, 'pivot'>
     */
    public function sessions(): BelongsToMany
    {
        return $this->belongsToMany(EventSession::class)->withPivot('seats');
    }

    /**
     * Cupos que consume por jornada: [event_session_id => seats].
     * Es la base del control de sobreventa.
     *
     * @return array<int, int>
     */
    public function consumoDeCupos(): array
    {
        return $this->sessions
            ->mapWithKeys(fn (EventSession $s) => [$s->id => (int) $s->pivot->getAttribute('seats')])
            ->all();
    }
}
