<?php

namespace App\Models;

use Database\Factories\EstablishmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Establishment extends Model
{
    /** @use HasFactory<EstablishmentFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @return BelongsToMany<Order, $this> */
    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class);
    }

    /** @return HasMany<Participant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class);
    }
}
