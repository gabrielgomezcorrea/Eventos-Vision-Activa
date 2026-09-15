<?php

namespace App\Models;

use App\Enums\EventModality;
use App\Enums\EventStatus;
use App\Enums\ReservationDurationUnit;
use App\Support\Forms\ProgramFormField;
use Carbon\CarbonImmutable;
use Database\Factories\EventFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Seminario, curso o evento. Todo el sistema cuelga de aquí: nada debe quedar
 * hardcodeado para un seminario en particular.
 *
 * @property EventStatus $status
 * @property EventModality $modality
 * @property ReservationDurationUnit $reservation_duration_unit
 * @property int $reservation_duration_value
 * @property int|null $reminder_hours_before
 * @property Carbon|null $starts_on
 * @property Carbon|null $replacement_deadline
 * @property array<int, array<string, mixed>>|null $program_form_fields
 */
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    /**
     * Los valores por defecto de la base no viven en memoria hasta releer el
     * modelo, así que un evento recién creado tenía `status` en null y
     * cualquier `$evento->status->label()` reventaba.
     */
    protected $attributes = [
        'status' => EventStatus::Borrador->value,
        'modality' => EventModality::Presencial->value,
        'reservation_duration_value' => 3,
        'reservation_duration_unit' => ReservationDurationUnit::DiasCorridos->value,
    ];

    /**
     * Texto de la casilla de comunicaciones cuando el evento no define el suyo.
     *
     * Declara la finalidad y no la herramienta: sirve igual si esos correos
     * terminan en el CRM o en una plataforma de campañas.
     */
    public const CONSENTIMIENTO_POR_DEFECTO = 'Quiero recibir información sobre este y otros eventos.';

    protected function casts(): array
    {
        return [
            'status' => EventStatus::class,
            'modality' => EventModality::class,
            'reservation_duration_unit' => ReservationDurationUnit::class,
            'reservation_duration_value' => 'integer',
            'reminder_hours_before' => 'integer',
            'starts_on' => 'date',
            'replacement_deadline' => 'date',
            'program_form_fields' => 'array',
        ];
    }

    /** @return HasMany<EventAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(EventAttachment::class)->oldest('id');
    }

    /** @return HasMany<DiscountTier, $this> */
    public function discountTiers(): HasMany
    {
        return $this->hasMany(DiscountTier::class)->orderBy('min_participants');
    }

    /**
     * Tramo que corresponde a esa cantidad de participantes, o null.
     *
     * Gana el tramo más alto que alcance: con "5 o más" y "10 o más"
     * configurados, doce personas reciben el de diez.
     */
    public function tramoDeDescuento(int $participantes): ?DiscountTier
    {
        return $this->discountTiers
            ->where('min_participants', '<=', $participantes)
            ->sortByDesc('min_participants')
            ->first();
    }

    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * Los datos bancarios salen de la cuenta elegida.
     *
     * Estos accessors existen para que los correos y las pantallas del cliente
     * sigan pidiendo `$evento->bank_name` sin saber de dónde viene. Las columnas
     * propias del evento quedaron como respaldo de lo que había antes de que las
     * cuentas fueran globales; si hay cuenta asignada, manda la cuenta.
     */
    public function getBankHolderNameAttribute(?string $valor): ?string
    {
        return $this->bankAccount->holder_name ?? $valor;
    }

    public function getBankHolderRutAttribute(?string $valor): ?string
    {
        return $this->bankAccount->holder_rut ?? $valor;
    }

    public function getBankNameAttribute(?string $valor): ?string
    {
        return $this->bankAccount->bank_name ?? $valor;
    }

    public function getBankAccountTypeAttribute(?string $valor): ?string
    {
        return $this->bankAccount->account_type ?? $valor;
    }

    public function getBankAccountNumberAttribute(?string $valor): ?string
    {
        return $this->bankAccount->account_number ?? $valor;
    }

    public function getBankEmailAttribute(?string $valor): ?string
    {
        return $this->bankAccount->email ?? $valor;
    }

    public function getPaymentInstructionsAttribute(?string $valor): ?string
    {
        return $this->bankAccount->payment_instructions ?? $valor;
    }

    /** @return HasMany<EventSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(EventSession::class)->orderBy('position');
    }

    /** @return HasMany<AccessType, $this> */
    public function accessTypes(): HasMany
    {
        return $this->hasMany(AccessType::class)->orderBy('position');
    }

    /** @return HasMany<ProgramRequest, $this> */
    public function programRequests(): HasMany
    {
        return $this->hasMany(ProgramRequest::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /** @return HasMany<Accreditation, $this> */
    public function accreditations(): HasMany
    {
        return $this->hasMany(Accreditation::class);
    }

    /**
     * Campos del formulario público. Sin configuración propia se usa el
     * conjunto base, de modo que un evento recién creado ya tiene formulario.
     *
     * @return array<int, ProgramFormField>
     */
    public function camposDelFormulario(): array
    {
        $definiciones = $this->program_form_fields ?: ProgramFormField::porDefectoComoArray();

        return collect($definiciones)
            ->map(fn (array $d) => ProgramFormField::fromArray($d))
            ->filter(fn (ProgramFormField $f) => $f->enabled)
            // El campo de jornada solo tiene sentido si hay accesos configurados.
            ->reject(fn (ProgramFormField $f) => $f->key === 'access_type_id' && $this->accessTypes()->where('is_active', true)->doesntExist())
            ->values()
            ->all();
    }

    /**
     * Opciones de "jornada de interés", tomadas de los accesos activos.
     *
     * @return array<int, string>
     */
    public function opcionesDeInteres(): array
    {
        return $this->accessTypes()
            ->where('is_active', true)
            ->orderBy('position')
            ->pluck('name', 'id')
            ->all();
    }

    /** Calcula el vencimiento de una reserva según el plazo configurado en el evento. */
    public function calcularVencimientoReserva(?DateTimeInterface $desde = null): CarbonImmutable
    {
        return $this->reservation_duration_unit->vencimientoDesde(
            $desde ?? CarbonImmutable::now(),
            $this->reservation_duration_value,
        );
    }

    public function admiteInscripciones(): bool
    {
        return $this->status->admiteInscripciones();
    }

    public function usaPulseras(): bool
    {
        return $this->modality->requiereAcreditacionPresencial();
    }

    /**
     * Lo que impide publicar el evento, dicho como lo entiende quien lo configura.
     *
     * @return array<int, string>
     */
    public function loQueFaltaParaPublicar(): array
    {
        $faltantes = [];

        if ($this->sessions()->doesntExist()) {
            $faltantes[] = 'las jornadas';
        }

        if ($this->accessTypes()->doesntExist()) {
            $faltantes[] = 'los tipos de acceso';
        }

        if ($this->bank_account_id === null) {
            $faltantes[] = 'la cuenta para las transferencias';
        }

        return $faltantes;
    }

    /**
     * Identificador de URL libre a partir del nombre.
     *
     * `Str::slug` ya convierte tildes y ñ y descarta los símbolos, así que
     * "Seminario Ñandú: 2ª versión" queda como "seminario-nandu-2a-version".
     * Si se repite, se numera en vez de fallar con un error que la persona no
     * sabría corregir.
     */
    public static function identificadorLibre(string $nombre): string
    {
        $base = Str::slug($nombre) ?: 'evento';
        $candidato = $base;
        $numero = 2;

        while (self::withTrashed()->where('slug', $candidato)->exists()) {
            $candidato = $base.'-'.$numero++;
        }

        return $candidato;
    }
}
