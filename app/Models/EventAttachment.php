<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Documento que viaja adjunto en el correo con el programa.
 *
 * @property int $size
 */
class EventAttachment extends Model
{
    /** Tope por evento. Más de tres y el correo pesa más de lo que nadie lee. */
    public const MAXIMO = 5;

    /** Tamaño máximo por archivo, en MB. */
    public const MAX_MB = 10;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function existe(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }

    public function tamanoLegible(): string
    {
        $mb = $this->size / 1024 / 1024;

        return $mb >= 1
            ? number_format($mb, 1, ',', '.').' MB'
            : max(1, (int) round($this->size / 1024)).' KB';
    }
}
