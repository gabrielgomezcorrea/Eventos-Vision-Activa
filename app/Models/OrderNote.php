<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Anotación con fecha y autor sobre una inscripción, solo para el equipo. No se
 * edita ni se borra: es el registro de lo que se habló o se hizo.
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $user_id
 * @property string $body
 * @property Carbon $created_at
 */
class OrderNote extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
