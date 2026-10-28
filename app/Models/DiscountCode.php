<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\TipoDescuento;
use App\Support\CodigoDeDescuento;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Código de descuento de un evento, con tope de personas y fecha de vencimiento.
 *
 * @property int $id
 * @property int $event_id
 * @property string $code
 * @property TipoDescuento $type
 * @property int $value
 * @property int $max_people
 * @property int $used_people
 * @property string $uses_per_person
 * @property Carbon $expires_at
 * @property bool $is_active
 */
class DiscountCode extends Model
{
    use HasFactory;

    protected $guarded = [];

    /** Usos por persona: en todos sus recorridos del evento o en uno solo. */
    public const USOS_POR_PERSONA = [
        'unlimited' => 'Sin límite de uso',
        'once' => 'Una sola vez',
    ];

    protected $attributes = [
        'uses_per_person' => 'unlimited',
        'type' => 'percent',
        'used_people' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'type' => TipoDescuento::class,
            'value' => 'integer',
            'max_people' => 'integer',
            'used_people' => 'integer',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function disponibles(): int
    {
        return max(0, $this->max_people - $this->used_people);
    }

    /**
     * Quiénes lo usaron: una persona por correo de solicitante, con sus
     * inscripciones vivas (confirmadas o pagadas) hechas con este código.
     *
     * @return Collection<string, \Illuminate\Database\Eloquent\Collection<int, Order>>
     */
    public function usosPorPersona(): Collection
    {
        return Order::query()
            ->where('discount_code_id', $this->getKey())
            ->whereIn('status', [OrderStatus::Reservada, OrderStatus::Finalizada])
            ->with('establishments')
            ->orderBy('confirmed_at')
            ->get()
            ->groupBy(fn (Order $orden): string => self::personaDe($orden));
    }

    /** El código como se lee y se dicta: XXXX-XXXX. */
    public function formateado(): string
    {
        return CodigoDeDescuento::formatear($this->code);
    }

    /** Cómo se le explica a quien lo ve: "10%" o "$50.000". */
    public function etiqueta(): string
    {
        if ($this->type === TipoDescuento::Porcentaje && $this->value === 100) {
            return 'Descuento completo';
        }

        return $this->type === TipoDescuento::Porcentaje
            ? $this->value.'%'
            : '$'.number_format($this->value, 0, ',', '.');
    }

    /**
     * Cuánto se rebaja de un subtotal, nunca más que el subtotal.
     */
    public function calcular(int $subtotal): int
    {
        $rebaja = $this->type === TipoDescuento::Porcentaje
            ? (int) floor($subtotal * $this->value / 100)
            : $this->value;

        return (int) min($rebaja, $subtotal);
    }

    /** @param  Collection<int, Participant>  $participantes */
    public static function mensajePorCodigoPrevio(Collection $participantes): string
    {
        return $participantes->map->nombre_completo->implode(', ')
            .($participantes->count() > 1 ? ' ya usaron' : ' ya usó')
            .' un código de descuento en este evento. Cada persona puede usar solo uno.';
    }

    /** Por qué no se puede usar para tantas personas, o null si se puede. */
    /**
     * Por qué esta inscripción no puede usar el código, o null si puede. Cada
     * persona es un correo de solicitante: quien ya lo usa no ocupa otro cupo,
     * y con "Una sola vez" no lo vuelve a usar en otro recorrido.
     */
    public function impedimentoPara(Order $orden, bool $ignorarVigencia = false): ?string
    {
        if (! $ignorarVigencia && ! $this->is_active) {
            return 'Este código ya no está disponible.';
        }

        if (! $ignorarVigencia && $this->expires_at->isPast()) {
            return 'Este código venció el '.$this->expires_at->format('d-m-Y').'.';
        }

        $previas = $this->inscripcionesVivasDe(self::personaDe($orden), $orden);

        if ($this->uses_per_person === 'once' && $previas->contains(fn (Order $o): bool => $orden->group_id === null || $o->group_id !== $orden->group_id)) {
            return 'Este código es de una sola vez y ya lo usaste en la inscripción '.$previas->first()->number.'.';
        }

        if ($previas->isEmpty() && $this->disponibles() === 0) {
            return 'Este código ya alcanzó el máximo de personas que pueden usarlo.';
        }

        return null;
    }

    /**
     * Ocupa el cupo de la persona al confirmar. Si ya lo usa (otro colegio o
     * recorrido suyo) no ocupa otro. El cupo nuevo se toma con un UPDATE
     * condicional: dos personas distintas no se llevan el último a la vez.
     */
    public function tomarPersona(Order $orden, bool $ignorarVigencia = false): bool
    {
        if ($this->inscripcionesVivasDe(self::personaDe($orden), $orden)->isNotEmpty()) {
            return true;
        }

        return $this->tomarUsos(1, $ignorarVigencia);
    }

    /**
     * Vuelve a contar las personas que lo usan después de que una inscripción
     * venció, se canceló o se borró: el cupo se libera solo si esa persona ya
     * no tiene otra inscripción viva con el código.
     */
    public function recalcularUsos(): void
    {
        $personas = Order::query()
            ->where('discount_code_id', $this->getKey())
            ->whereIn('status', [OrderStatus::Reservada, OrderStatus::Finalizada])
            ->distinct()
            ->count('discount_code_email');

        $this->forceFill(['used_people' => $personas])->save();
    }

    /**
     * La persona que usa el código: quien lo aplicó, no el responsable de hoy
     * (el responsable puede cambiar de correo). Sin código aplicado todavía,
     * es quien lo está aplicando ahora.
     */
    private static function personaDe(Order $orden): string
    {
        return $orden->discount_code_email ?? mb_strtolower(trim($orden->responsible_email));
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Order> */
    private function inscripcionesVivasDe(string $correo, Order $excepto): \Illuminate\Database\Eloquent\Collection
    {
        return Order::query()
            ->where('discount_code_id', $this->getKey())
            ->where('discount_code_email', mb_strtolower(trim($correo)))
            ->whereKeyNot($excepto->getKey())
            ->whereIn('status', [OrderStatus::Reservada, OrderStatus::Finalizada])
            ->orderBy('id')
            ->get();
    }

    /**
     * Consume usos con un UPDATE condicional: la comprobación del tope viaja en
     * la misma sentencia que el incremento, como en ReservarCupos. Leer y
     * después escribir deja pasar a dos personas que llegan a la vez.
     *
     * `$ignorarVigencia` es para reactivar una reserva: la orden ya tenía el
     * código, así que no importa que haya vencido ni que lo hayan desactivado.
     */
    public function tomarUsos(int $personas, bool $ignorarVigencia = false): bool
    {
        $consulta = static::query()
            ->whereKey($this->getKey())
            ->whereRaw('used_people + ? <= max_people', [$personas]);

        if (! $ignorarVigencia) {
            $consulta->where('is_active', true)->where('expires_at', '>=', now());
        }

        return $consulta->increment('used_people', $personas) > 0;
    }

    /** Devuelve usos al vencer o cancelar una inscripción, sin bajar de cero. */
    public function devolverUsos(int $personas): void
    {
        DB::update(
            'update discount_codes set used_people = CASE WHEN used_people < ? THEN 0 ELSE used_people - ? END where id = ?',
            [$personas, $personas, $this->getKey()],
        );
    }

    /** @return 'inactive'|'expired'|'exhausted'|'active' */
    public function estado(): string
    {
        return match (true) {
            ! $this->is_active => 'inactive',
            $this->expires_at->isPast() => 'expired',
            $this->disponibles() === 0 => 'exhausted',
            default => 'active',
        };
    }
}
