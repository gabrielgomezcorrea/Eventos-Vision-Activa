<?php

namespace App\Models;

use App\Enums\CertificateStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A certificate PDF made outside the system, uploaded to its event and matched
 * to one participant (24/11/2026). The file lives on the private disk.
 *
 * @property int $id
 * @property int $event_id
 * @property int|null $participant_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $file_hash
 * @property string|null $pdf_text
 * @property string|null $detected_name
 * @property int|null $match_score
 * @property CertificateStatus $status
 * @property Carbon|null $queued_at
 * @property Carbon|null $sending_at
 * @property Carbon|null $sent_at
 * @property string|null $sent_to
 * @property int $attempts
 * @property string|null $last_error
 */
class Certificate extends Model
{
    protected $guarded = [];

    // A new model does not hold the database defaults: without this the enum comparisons fail silently.
    protected $attributes = [
        'status' => 'review',
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => CertificateStatus::class,
            'queued_at' => 'datetime',
            'sending_at' => 'datetime',
            'sent_at' => 'datetime',
            'match_score' => 'integer',
            'attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Participant, $this> */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    /** Name for the attached file: the person's name, not the uploaded file's. */
    public function nombreDelAdjunto(): string
    {
        $nombre = $this->participant->nombre_completo ?? $this->detected_name ?? pathinfo($this->original_name, PATHINFO_FILENAME);

        return 'Certificado - '.trim(preg_replace('/[\\\\\/:*?"<>|]+/', ' ', $nombre) ?? 'participante').'.pdf';
    }
}
