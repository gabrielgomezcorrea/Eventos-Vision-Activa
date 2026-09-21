<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Agrupa las órdenes de un mismo responsable en un mismo evento, para dar un
 * solo correo, un solo enlace y una sola pantalla.
 *
 * Cada orden del conjunto sigue siendo dueña de su propio dinero, sus cupos,
 * sus abonos y sus credenciales: el conjunto no tiene estado ni saldo propio.
 */
class OrderGroup extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'group_id');
    }

    /** @return HasMany<MagicLink, $this> */
    public function magicLinks(): HasMany
    {
        return $this->hasMany(MagicLink::class, 'order_group_id');
    }

    /**
     * Conjunto vigente de este responsable en el evento, o uno nuevo. Siempre
     * se crea uno, aunque termine con una sola orden: dos caminos distintos en
     * el código es lo que después nadie mantiene.
     */
    public static function paraResponsable(Event $event, string $email): self
    {
        return self::query()
            ->where('event_id', $event->getKey())
            ->where('responsible_email', $email)
            ->whereHas('orders', fn ($query) => $query->whereIn('status', [
                OrderStatus::Borrador->value,
                OrderStatus::Reservada->value,
            ]))
            ->latest('id')
            ->first() ?? self::create([
                'event_id' => $event->getKey(),
                'responsible_email' => $email,
            ]);
    }
}
