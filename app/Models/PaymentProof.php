<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Archivo de comprobante. La base guarda solo metadata; el archivo vive en el
 * disco privado y nunca se expone por URL directa.
 *
 * @property int $size
 */
class PaymentProof extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function existe(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }

    public function tamanoLegible(): string
    {
        $kb = $this->size / 1024;

        return $kb < 1024
            ? round($kb).' KB'
            : round($kb / 1024, 1).' MB';
    }

    public function esImagen(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }
}
