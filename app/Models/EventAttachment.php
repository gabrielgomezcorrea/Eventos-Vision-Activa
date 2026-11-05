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
    /** Tamaño máximo de cada archivo, en MB. */
    public const MAX_MB = 10;

    /**
     * Lo que viaja adjunto en un correo, sumado, en MB. Gmail rechaza más de
     * 25 MB y el adjunto crece un tercio al codificarse: con adjuntos grandes
     * el correo fallaba y la cola lo reintentaba sin fin. Lo que no cabe sigue
     * disponible en el portal, pero no viaja.
     */
    public const MAX_CORREO_MB = 15;

    /** Documentos de oficina e imágenes. Nada ejecutable ni que corra en el navegador (html, svg). */
    public const EXTENSIONES = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'txt', 'csv', 'jpg', 'jpeg', 'png', 'webp'];

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

    /**
     * Los adjuntos que viajan en el correo: en orden de subida, mientras la
     * suma quepa.
     *
     * @param  iterable<int, self>  $adjuntos
     * @return array<int, self>
     */
    public static function paraElCorreo(iterable $adjuntos): array
    {
        $usado = 0;
        $viajan = [];

        foreach ($adjuntos as $adjunto) {
            if (! $adjunto->existe() || $usado + $adjunto->size > self::MAX_CORREO_MB * 1024 * 1024) {
                continue;
            }

            $usado += $adjunto->size;
            $viajan[] = $adjunto;
        }

        return $viajan;
    }

    public function tamanoLegible(): string
    {
        $mb = $this->size / 1024 / 1024;

        return $mb >= 1
            ? number_format($mb, 1, ',', '.').' MB'
            : max(1, (int) round($this->size / 1024)).' KB';
    }
}
