<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Una cuenta bancaria de la empresa.
 *
 * Se configura una vez y los eventos la eligen. Es global a propósito: los
 * datos de transferencia son de la empresa, no de cada seminario.
 *
 * @property bool $is_active
 */
class BankAccount extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = [];

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<Event, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /** Cómo se identifica en un selector: el alias y el número, que es lo que distingue. */
    public function etiquetaCompleta(): string
    {
        return $this->label.' — '.$this->bank_name.' '.$this->account_number;
    }
}
