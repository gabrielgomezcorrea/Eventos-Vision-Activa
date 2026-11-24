<?php

namespace App\Models;

use App\Enums\CertificateCampaignStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The spaced sending of one event's certificates. One row per event: starting
 * again reuses it.
 *
 * @property int $id
 * @property int $event_id
 * @property CertificateCampaignStatus $status
 * @property Carbon|null $next_send_at
 * @property int $consecutive_failures
 * @property string|null $paused_reason
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class CertificateCampaign extends Model
{
    protected $guarded = [];

    protected $attributes = [
        'status' => 'sending',
        'consecutive_failures' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => CertificateCampaignStatus::class,
            'next_send_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'consecutive_failures' => 'integer',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
