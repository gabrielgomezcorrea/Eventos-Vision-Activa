<?php

namespace App\Models;

use Database\Factories\ProgramRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Solicitud de programa capturada por el formulario público breve.
 * Es el paso previo a la inscripción: todavía no hay orden ni participantes.
 *
 * @property array<string, mixed>|null $extra
 * @property Carbon|null $consented_at
 * @property Carbon|null $marketing_consented_at
 * @property Carbon|null $program_sent_at
 * @property Carbon|null $converted_at
 */
class ProgramRequest extends Model
{
    /** @use HasFactory<ProgramRequestFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'extra' => 'array',
            'consented_at' => 'datetime',
            'marketing_consented_at' => 'datetime',
            'program_sent_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<AccessType, $this> */
    public function accessType(): BelongsTo
    {
        return $this->belongsTo(AccessType::class);
    }

    public function getNombreCompletoAttribute(): string
    {
        return trim($this->first_name.' '.($this->last_name ?? ''));
    }

    public function yaConvertida(): bool
    {
        return $this->converted_at !== null;
    }

    /**
     * En qué punto del seguimiento está.
     *
     * Se deriva de las fechas, no se guarda: una columna de estado que hay que
     * mantener sincronizada con esas mismas fechas termina contradiciéndolas.
     */
    public function estado(): string
    {
        return match (true) {
            $this->converted_at !== null => 'Ya se inscribió',
            $this->program_sent_at !== null => 'Programa enviado',
            default => 'Nueva',
        };
    }

    public function colorDelEstado(): string
    {
        return match (true) {
            $this->converted_at !== null => 'success',
            $this->program_sent_at !== null => 'info',
            default => 'warning',
        };
    }
}
