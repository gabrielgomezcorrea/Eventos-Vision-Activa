<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\ProgramRequest;
use Database\Faker\ChileProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProgramRequest> */
class ProgramRequestFactory extends Factory
{
    protected $model = ProgramRequest::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'position' => ChileProvider::fake()->cargoEducacional(),
            'institution' => ChileProvider::fake()->nombreEstablecimiento(),
            'phone' => fake()->boolean(70) ? ChileProvider::fake()->telefonoChileno() : null,
            'consented_at' => now()->subDays(fake()->numberBetween(0, 30)),
            'program_sent_at' => now()->subDays(fake()->numberBetween(0, 30)),
            'source_url' => 'https://www.liderazgoescolar.cl/seminario',
            'ip_address' => fake()->ipv4(),
        ];
    }

    public function convertida(): static
    {
        return $this->state(['converted_at' => now()->subDays(fake()->numberBetween(0, 10))]);
    }

    public function sinConsentimiento(): static
    {
        return $this->state(['consented_at' => null]);
    }
}
