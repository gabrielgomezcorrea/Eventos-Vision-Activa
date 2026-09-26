<?php

namespace Database\Factories;

use App\Enums\TipoDescuento;
use App\Models\DiscountCode;
use App\Models\Event;
use App\Support\CodigoDeDescuento;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiscountCode>
 */
class DiscountCodeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'code' => CodigoDeDescuento::generar(),
            'type' => TipoDescuento::Porcentaje,
            'value' => 10,
            'max_people' => 20,
            'expires_at' => now()->addWeek()->endOfDay(),
        ];
    }

    public function gratis(): static
    {
        return $this->state(['type' => TipoDescuento::Porcentaje, 'value' => 100]);
    }
}
