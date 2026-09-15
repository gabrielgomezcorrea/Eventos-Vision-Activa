<?php

namespace Database\Factories;

use App\Enums\ParticipantStatus;
use App\Models\Participant;
use Database\Faker\ChileProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Participant> */
class ParticipantFactory extends Factory
{
    protected $model = Participant::class;

    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName().' '.fake()->lastName(),
            // Uno de cada cuatro llega sin RUT: pasa de verdad en las
            // inscripciones institucionales.
            'rut' => fake()->boolean(75) ? ChileProvider::fake()->rutChileno() : null,
            'position' => ChileProvider::fake()->cargoEducacional(),
            'email' => fake()->boolean(80) ? fake()->unique()->safeEmail() : null,
            'phone' => fake()->boolean(40) ? ChileProvider::fake()->telefonoChileno() : null,
            'status' => ParticipantStatus::Registrado,
            'unit_price' => 0,
        ];
    }

    public function sinCorreo(): static
    {
        return $this->state(['email' => null]);
    }

    public function sinRut(): static
    {
        return $this->state(['rut' => null]);
    }
}
