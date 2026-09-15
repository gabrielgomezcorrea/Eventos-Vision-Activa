<?php

namespace Database\Factories;

use App\Models\Establishment;
use Database\Faker\ChileProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Establishment> */
class EstablishmentFactory extends Factory
{
    protected $model = Establishment::class;

    public function definition(): array
    {
        return [
            'name' => ChileProvider::fake()->nombreEstablecimiento(),
            'rbd' => ChileProvider::fake()->rbd(),
            'address' => fake()->streetAddress(),
            'commune' => ChileProvider::fake()->comuna(),
        ];
    }

    /** Algunos establecimientos llegan sin RBD: el dato no siempre existe. */
    public function sinRbd(): static
    {
        return $this->state(['rbd' => null]);
    }
}
