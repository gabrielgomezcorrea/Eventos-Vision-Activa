<?php

namespace App\Models;

use App\Enums\EtapaDeSeguimiento;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Closure;
use Database\Factories\ProgramRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Solicitud de programa capturada por el formulario público breve.
 * Es el paso previo a la inscripción: todavía no hay orden ni participantes.
 *
 * @property array<string, mixed>|null $extra
 * @property Carbon|null $consented_at
 * @property Carbon|null $marketing_consented_at
 * @property Carbon|null $program_sent_at
 * @property Carbon|null $converted_at
 * @property Carbon|null $declined_at
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
            'declined_at' => 'datetime',
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

    /** Dijo que no va, sea cual sea la etapa que le toque por sus órdenes. */
    public function noParticipara(): bool
    {
        return $this->declined_at !== null;
    }

    /**
     * La etapa de esta persona, con las mismas reglas que filtran el listado:
     * se pregunta a la base por cada una en vez de repetir la lógica en PHP,
     * para que la ficha y el listado nunca digan cosas distintas.
     */
    public function etapa(): EtapaDeSeguimiento
    {
        foreach ([EtapaDeSeguimiento::Inscrito, EtapaDeSeguimiento::PreInscrito, EtapaDeSeguimiento::NoParticipara] as $etapa) {
            if (self::query()->whereKey($this->getKey())->enEtapa($etapa)->exists()) {
                return $etapa;
            }
        }

        return EtapaDeSeguimiento::Solicito;
    }

    /** Dentro de «Solicitó», cómo va el seguimiento del equipo. */
    public function seguimiento(): string
    {
        return match (true) {
            $this->tieneNotas() => 'Contactada',
            $this->program_sent_at !== null => 'Programa enviado',
            default => 'Nueva',
        };
    }

    /**
     * Within «Solicitó», whether the team already wrote them a note:
     * `sin` = Nueva solicitud, `contactado` = Contactado, null = everyone.
     *
     * @param  Builder<self>  $consulta
     * @return Builder<self>
     */
    public function scopeDeSeguimiento(Builder $consulta, ?string $seguimiento): Builder
    {
        return match ($seguimiento) {
            'sin' => $consulta->whereDoesntHave('notes'),
            'contactado' => $consulta->whereHas('notes'),
            default => $consulta,
        };
    }

    /**
     * La búsqueda y el evento del listado, compartidos con su exportación para
     * que el archivo traiga exactamente lo que se ve en pantalla.
     *
     * @param  Builder<self>  $consulta
     * @param  array{buscar?: string|null, evento?: int|string|null}  $filtros
     * @return Builder<self>
     */
    public function scopeDelListado(Builder $consulta, array $filtros): Builder
    {
        return $consulta
            ->when($filtros['buscar'] ?? null, fn (Builder $q, string $buscar) => $q->where(
                fn (Builder $s) => $s->where('first_name', 'like', "%{$buscar}%")
                    ->orWhere('last_name', 'like', "%{$buscar}%")
                    ->orWhere('email', 'like', "%{$buscar}%")
                    ->orWhere('institution', 'like', "%{$buscar}%"),
            ))
            ->when($filtros['evento'] ?? null, fn (Builder $q, int|string $evento) => $q->where('event_id', (int) $evento));
    }

    /**
     * Las cuatro etapas se excluyen entre sí y cubren a todos:
     *
     * - Inscrito: alguna orden pagada y ninguna confirmada esperando pago.
     * - Pre inscrito: una orden confirmada esperando pago (aunque la hayan
     *   marcado «No participará»: confirmar pesa más), o, sin esa marca, un
     *   borrador con datos o una reserva vencida o cancelada que recuperar.
     * - No participará: la marca, sin nada pagado ni por pagar.
     * - Solicitó: el resto.
     *
     * Las órdenes de la persona son las que salieron de esta solicitud o las del
     * mismo evento con su correo como responsable: las anteriores al enlace
     * estable solo se reconocen por el correo, y las adicionales lo heredan.
     *
     * @param  Builder<self>  $consulta
     * @return Builder<self>
     */
    public function scopeEnEtapa(Builder $consulta, EtapaDeSeguimiento $etapa): Builder
    {
        $pagada = fn (QueryBuilder $o) => $o
            ->whereIn('orders.status', [OrderStatus::Reservada->value, OrderStatus::Finalizada->value])
            ->where('orders.payment_status', PaymentStatus::Aprobado->value);
        $esperandoPago = fn (QueryBuilder $o) => $o
            ->where('orders.status', OrderStatus::Reservada->value)
            ->where('orders.payment_status', '!=', PaymentStatus::Aprobado->value);
        // Un borrador nace vacío al hacer clic en el enlace: solo cuenta si ya
        // escribió algo. Lo vencido o cancelado también queda por recuperar.
        $aMedias = fn (QueryBuilder $o) => $o->where(fn (QueryBuilder $c) => $c
            ->whereIn('orders.status', [OrderStatus::Vencida->value, OrderStatus::Cancelada->value])
            ->orWhere(fn (QueryBuilder $b) => $b
                ->where('orders.status', OrderStatus::Borrador->value)
                ->where(fn (QueryBuilder $d) => $d
                    ->where('orders.responsible_name', '!=', '')
                    ->orWhereExists(fn (QueryBuilder $p) => $p->selectRaw('1')->from('participants')->whereColumn('participants.order_id', 'orders.id')))));

        $inscrita = fn (Builder $q) => $q
            ->whereExists(self::ordenes($pagada))
            ->whereNotExists(self::ordenes($esperandoPago));

        return match ($etapa) {
            EtapaDeSeguimiento::Inscrito => $inscrita($consulta),
            EtapaDeSeguimiento::PreInscrito => $consulta
                ->whereNot(fn (Builder $q) => $inscrita($q))
                ->where(fn (Builder $q) => $q
                    ->whereExists(self::ordenes($esperandoPago))
                    ->orWhere(fn (Builder $s) => $s->whereNull('declined_at')->whereExists(self::ordenes($aMedias)))),
            EtapaDeSeguimiento::NoParticipara => $consulta
                ->whereNotNull('declined_at')
                ->whereNot(fn (Builder $q) => $inscrita($q))
                ->whereNotExists(self::ordenes($esperandoPago)),
            EtapaDeSeguimiento::Solicito => $consulta
                ->whereNull('declined_at')
                ->whereNot(fn (Builder $q) => $inscrita($q))
                ->whereNotExists(self::ordenes($esperandoPago))
                ->whereNotExists(self::ordenes($aMedias)),
        };
    }

    /**
     * Subconsulta de las órdenes de la persona que cumplen una condición.
     *
     * @param  Closure(QueryBuilder): mixed  $condicion
     * @return Closure(QueryBuilder): void
     */
    private static function ordenes(Closure $condicion): Closure
    {
        return function (QueryBuilder $o) use ($condicion): void {
            $o->selectRaw('1')->from('orders')
                ->where(fn (QueryBuilder $v) => $v
                    ->whereColumn('orders.program_request_id', 'program_requests.id')
                    ->orWhere(fn (QueryBuilder $c) => $c
                        ->whereColumn('orders.event_id', 'program_requests.event_id')
                        ->whereColumn('orders.responsible_email', 'program_requests.email')));
            $condicion($o);
        };
    }

    /**
     * Las órdenes de cada persona de una página, en una sola consulta, para
     * decir en el listado en qué quedó su inscripción. Deja cargada la relación
     * `orders` con todas ellas, también las que solo calzan por correo.
     *
     * @param  Collection<int, self>  $solicitudes
     * @return array<int, Collection<int, Order>>
     */
    public static function ordenesDe(Collection $solicitudes): array
    {
        if ($solicitudes->isEmpty()) {
            return [];
        }

        $ordenes = Order::query()
            ->where(fn (Builder $q) => $q
                ->whereIn('program_request_id', $solicitudes->pluck('id'))
                ->orWhere(fn (Builder $c) => $c
                    ->whereIn('event_id', $solicitudes->pluck('event_id')->filter()->unique())
                    ->whereIn('responsible_email', $solicitudes->pluck('email')->unique())))
            ->get(['id', 'program_request_id', 'event_id', 'status', 'payment_status', 'responsible_email', 'responsible_name', 'responsible_lastname', 'responsible_phone', 'responsible_position', 'responsible_institution']);

        return $solicitudes->mapWithKeys(function (self $s) use ($ordenes): array {
            $suyas = $ordenes->filter(fn (Order $o): bool => $o->program_request_id === $s->id
                || ($o->event_id === $s->event_id && $o->responsible_email === $s->email))->values();
            // Lo que escribió como responsable completa lo que la solicitud no trae.
            $s->setRelation('orders', new EloquentCollection($suyas->all()));

            return [$s->id => $suyas];
        })->all();
    }

    /**
     * En qué quedó la inscripción de un pre inscrito, para saber qué decirle:
     * primero lo que está esperando pago, después lo que quedó a medias.
     *
     * @param  Collection<int, Order>  $ordenes
     */
    public static function detalleDePreInscrito(Collection $ordenes): ?string
    {
        $reservada = $ordenes->first(fn (Order $o): bool => $o->status === OrderStatus::Reservada && $o->payment_status !== PaymentStatus::Aprobado);

        if ($reservada !== null) {
            return match ($reservada->payment_status) {
                PaymentStatus::EnValidacion => 'Pago en revisión',
                PaymentStatus::Observado => 'Pago observado',
                PaymentStatus::Rechazado => 'Pago rechazado',
                default => 'Confirmó, falta pagar',
            };
        }

        return match (true) {
            $ordenes->contains(fn (Order $o): bool => $o->status === OrderStatus::Borrador) => 'Dejó la inscripción a medias',
            $ordenes->contains(fn (Order $o): bool => $o->status === OrderStatus::Vencida) => 'Reserva vencida',
            $ordenes->contains(fn (Order $o): bool => $o->status === OrderStatus::Cancelada) => 'Inscripción cancelada',
            default => null,
        };
    }
}
