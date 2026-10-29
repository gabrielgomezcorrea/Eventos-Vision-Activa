<?php

namespace App\Models;

use Database\Factories\ProgramRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public const ORIGEN_FORMULARIO = 'form';

    public const ORIGEN_DIRECTO = 'direct';

    protected $guarded = [];

    /** Un modelo recién creado no trae los defaults de la base. */
    protected $attributes = [
        'origin' => self::ORIGEN_FORMULARIO,
    ];

    protected function casts(): array
    {
        return [
            'extra' => 'array',
            'consented_at' => 'datetime',
            'marketing_consented_at' => 'datetime',
            'program_sent_at' => 'datetime',
            'converted_at' => 'datetime',
            'program_sent_count' => 'integer',
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

    /** Deja constancia de un envío del programa: la fecha y el contador. */
    public function registrarEnvioDelPrograma(): void
    {
        $this->forceFill(['program_sent_at' => now()])->save();
        $this->increment('program_sent_count');
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<ProgramRequestNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(ProgramRequestNote::class)->latest('id');
    }

    /** Usa el conteo ya cargado del listado si lo hay; si no, consulta. */
    public function tieneNotas(): bool
    {
        return isset($this->attributes['notes_count'])
            ? (int) $this->attributes['notes_count'] > 0
            : $this->notes()->exists();
    }

    public function getNombreCompletoAttribute(): string
    {
        return trim($this->first_name.' '.($this->last_name ?? ''));
    }

    /** Nombre para el panel; una solicitud directa solo tiene correo. */
    public function nombreParaMostrar(): string
    {
        return $this->nombre_completo !== '' ? $this->nombre_completo : $this->email;
    }

    /**
     * Institución de la solicitud o, si no la dio (inscripción directa), la que
     * puso el responsable en su inscripción. Usa las órdenes ya cargadas si las hay.
     */
    public function institucionParaMostrar(): ?string
    {
        return $this->datoOResponsable('institution', 'responsible_institution');
    }

    /**
     * Un dato de la solicitud o, si viene vacío, el que escribió el responsable
     * en su inscripción: una solicitud directa nace solo con el correo.
     */
    public function datoOResponsable(string $campo, string $delResponsable): ?string
    {
        if (filled($this->{$campo})) {
            return $this->{$campo};
        }

        $ordenes = $this->relationLoaded('orders') ? $this->orders->sortByDesc('id') : $this->orders()->latest('id')->get();

        return $ordenes->pluck($delResponsable)->first(fn ($valor) => filled($valor));
    }

    public function esDirecta(): bool
    {
        return $this->origin === self::ORIGEN_DIRECTO;
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
            $this->tieneNotas() => 'Contactada',
            $this->program_sent_at !== null => 'Programa enviado',
            default => 'Nueva',
        };
    }

    public function colorDelEstado(): string
    {
        return match (true) {
            $this->converted_at !== null => 'success',
            $this->tieneNotas() => 'success',
            $this->program_sent_at !== null => 'info',
            default => 'warning',
        };
    }
}
