<?php

namespace Database\Factories;

use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\PayerEntity;
use Database\Faker\ChileProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'payer_entity_id' => PayerEntity::factory(),
            'status' => OrderStatus::Borrador,
            'payment_status' => PaymentStatus::Pendiente,
            'kind' => OrderKind::Institucional,
            'responsible_name' => fake()->firstName(),
            'responsible_lastname' => fake()->lastName(),
            'responsible_email' => fake()->unique()->safeEmail(),
            'responsible_position' => ChileProvider::fake()->cargoEducacional(),
            'responsible_phone' => ChileProvider::fake()->telefonoChileno(),
            'responsible_institution' => ChileProvider::fake()->nombreEstablecimiento(),
        ];
    }

    public function particular(): static
    {
        return $this->state(['kind' => OrderKind::Particular]);
    }
}
