<?php

namespace App\Models;

use App\Enums\InvoiceDocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Registro de un documento tributario emitido fuera del sistema.
 *
 * @property InvoiceDocumentType $document_type
 * @property Carbon $issued_on
 * @property int $amount
 * @property int|null $size
 * @property Carbon|null $sent_at
 */
class InvoiceRecord extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'document_type' => InvoiceDocumentType::class,
            'issued_on' => 'date',
            'amount' => 'integer',
            'size' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by_user_id');
    }

    public function tieneArchivo(): bool
    {
        return $this->path !== null
            && Storage::disk($this->disk ?: config('filesystems.private_disk'))->exists($this->path);
    }

    public function descripcion(): string
    {
        return $this->document_type->label().' N° '.$this->number;
    }
}
