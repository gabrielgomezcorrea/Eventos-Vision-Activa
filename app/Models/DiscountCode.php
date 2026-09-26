<?php

namespace App\Models;

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
 * @property Carbon $expires_at
 * @property bool $is_active
 */
class DiscountCode extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = [
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
    public function impedimento(int $personas): ?string
    {
        return match (true) {
            ! $this->is_active => 'Este código ya no está disponible.',
            $this->expires_at->isPast() => 'Este código venció el '.$this->expires_at->format('d-m-Y').'.',
            $this->disponibles() === 0 => 'Este código ya no tiene cupos disponibles.',
            $personas > $this->disponibles() => 'El código no alcanza para todas las personas: quedan '.$this->disponibles().' usos.',
            default => null,
        };
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
