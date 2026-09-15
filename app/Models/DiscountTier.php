<?php

namespace App\Models;

use App\Enums\TipoDescuento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tramo de descuento por cantidad: "desde N participantes, tanto".
 *
 * @property TipoDescuento $type
 * @property int $min_participants
 * @property int $value
 */
class DiscountTier extends Model
{
    protected $guarded = [];

    protected $attributes = ['type' => 'percent'];

    protected function casts(): array
    {
        return [
            'type' => TipoDescuento::class,
            'min_participants' => 'integer',
            'value' => 'integer',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Cuánto se rebaja de un subtotal.
     *
     * Nunca más que el subtotal: un monto fijo mal configurado dejaría un total
     * negativo y una orden que el cliente tendría que cobrar.
     */
    public function calcular(int $subtotal): int
    {
        $rebaja = $this->type === TipoDescuento::Porcentaje
            ? (int) floor($subtotal * $this->value / 100)
            : $this->value;

        return (int) min($rebaja, $subtotal);
    }

    /** Cómo se le explica al cliente en el correo y en la pantalla. */
    public function etiqueta(): string
    {
        $rebaja = $this->type === TipoDescuento::Porcentaje
            ? $this->value.'%'
            : '$'.number_format($this->value, 0, ',', '.');

        return "{$rebaja} por {$this->min_participants} o más participantes";
    }
}
