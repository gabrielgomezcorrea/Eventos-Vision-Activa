<?php

namespace App\Models;

use App\Enums\PaymentReviewAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property PaymentReviewAction $action
 * @property Carbon $created_at
 */
class PaymentReview extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'action' => PaymentReviewAction::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
