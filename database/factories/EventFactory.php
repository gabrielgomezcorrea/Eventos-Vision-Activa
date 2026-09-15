<?php

namespace Database\Factories;

use App\Enums\EventModality;
use App\Enums\EventStatus;
use App\Enums\ReservationDurationUnit;
use App\Models\Event;
use Database\Faker\ChileProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Event> */
class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        $nombre = 'Seminario '.fake()->randomElement([
            'de Liderazgo Escolar', 'de Convivencia Escolar', 'de Evaluación para el Aprendizaje',
            'de Gestión Directiva', 'de Inclusión Educativa',
        ]).' '.now()->year;

        return [
            'name' => $nombre,
            'slug' => Str::slug($nombre).'-'.fake()->unique()->numberBetween(1, 9999),
            'order_prefix' => 'SI',
            'description' => fake()->paragraph(),
            'status' => EventStatus::Publicado,
            'modality' => EventModality::Presencial,
            'location' => 'Centro de Eventos '.fake()->lastName(),
            'address' => fake()->streetAddress(),
            'city' => ChileProvider::fake()->comuna(),
            'reservation_duration_value' => 3,
            'reservation_duration_unit' => ReservationDurationUnit::DiasHabiles,
            'replacement_deadline' => now()->addMonth(),
            'bank_holder_name' => 'ATE Liderazgo Educativo SpA',
            'bank_holder_rut' => ChileProvider::fake()->rutEmpresa(),
            'bank_name' => fake()->randomElement(['BancoEstado', 'Banco de Chile', 'Santander', 'BCI']),
            'bank_account_type' => 'Cuenta Corriente',
            'bank_account_number' => (string) fake()->numberBetween(10000000, 99999999),
            'bank_email' => 'pagos@ate.cl',
            'payment_instructions' => 'Las transferencias desde otros bancos pueden tardar hasta un día hábil en reflejarse.',
            'contact_name' => fake()->name(),
            'contact_email' => 'contacto@ate.cl',
            'contact_phone' => ChileProvider::fake()->telefonoChileno(),
        ];
    }

    public function borrador(): static
    {
        return $this->state(['status' => EventStatus::Borrador]);
    }

    public function online(): static
    {
        return $this->state([
            'modality' => EventModality::Online,
            'location' => null,
            'address' => null,
        ]);
    }

    /** Evento completo: dos jornadas y tres accesos, como los reales. */
    public function conJornadasYAccesos(int $cupoPorJornada = 300): static
    {
        return $this->afterCreating(function (Event $event) use ($cupoPorJornada): void {
            $j1 = $event->sessions()->create([
                'name' => 'Jornada 1', 'position' => 1, 'capacity' => $cupoPorJornada,
                'starts_at' => now()->addMonths(2)->setTime(9, 0),
                'ends_at' => now()->addMonths(2)->setTime(18, 0),
            ]);

            $j2 = $event->sessions()->create([
                'name' => 'Jornada 2', 'position' => 2, 'capacity' => (int) round($cupoPorJornada * 0.8),
                'starts_at' => now()->addMonths(2)->addDay()->setTime(9, 0),
                'ends_at' => now()->addMonths(2)->addDay()->setTime(18, 0),
            ]);

            $a1 = $event->accessTypes()->create([
                'name' => 'Jornada 1', 'position' => 1, 'price' => 90000,
                'wristband_label' => 'Día 1', 'wristband_color' => '#084887',
            ]);
            $a2 = $event->accessTypes()->create([
                'name' => 'Jornada 2', 'position' => 2, 'price' => 90000,
                'wristband_label' => 'Día 2', 'wristband_color' => '#909CC2',
            ]);
            $a3 = $event->accessTypes()->create([
                'name' => 'Ambas jornadas', 'position' => 3, 'price' => 150000,
                'wristband_label' => 'Acceso completo', 'wristband_color' => '#F58A07',
            ]);

            $a1->sessions()->sync([$j1->id => ['seats' => 1]]);
            $a2->sessions()->sync([$j2->id => ['seats' => 1]]);
            $a3->sessions()->sync([$j1->id => ['seats' => 1], $j2->id => ['seats' => 1]]);
        });
    }
}
