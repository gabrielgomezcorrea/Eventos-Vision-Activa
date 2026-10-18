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
    /**
     * Tamaño máximo del programa, en MB. Con adjuntos grandes Gmail rechazaba
     * el primer correo (más de 25 MB) y la cola lo reintentaba sin fin.
     */
    public const MAX_MB = 5;

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

    /** Cabe en el correo: existe en disco y pesa el máximo o menos. */
    public function cabeEnElCorreo(): bool
    {
        return $this->size <= self::MAX_MB * 1024 * 1024 && $this->existe();
    }

    public function tamanoLegible(): string
    {
        $mb = $this->size / 1024 / 1024;

        return $mb >= 1
            ? number_format($mb, 1, ',', '.').' MB'
            : max(1, (int) round($this->size / 1024)).' KB';
    }
}
