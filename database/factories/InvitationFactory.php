<?php

namespace Database\Factories;

use App\Models\AccessType;
use App\Models\Event;
use App\Models\Invitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'access_type_id' => fn (array $atributos) => AccessType::create(['event_id' => $atributos['event_id'], 'name' => 'Curso completo', 'position' => 1, 'price' => 0])->id,
            'email' => fake()->unique()->safeEmail(),
            'token_hash' => Invitation::hash(Str::random(64)),
            'expires_at' => now()->addWeek()->endOfDay(),
        ];
    }
}
