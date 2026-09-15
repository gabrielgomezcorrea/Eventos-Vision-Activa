<?php

namespace App\Models;

use Database\Factories\PayerEntityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Entidad a cuyo nombre se emite la factura. Puede ser distinta del
 * establecimiento: una fundación o municipalidad puede pagar por participantes
 * de varios colegios.
 */
class PayerEntity extends Model
{
    /** @use HasFactory<PayerEntityFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
