<?php

namespace Database\Factories;

use App\Models\PayerEntity;
use Database\Faker\ChileProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PayerEntity> */
class PayerEntityFactory extends Factory
{
    protected $model = PayerEntity::class;

    public function definition(): array
    {
        return [
            'name' => ChileProvider::fake()->nombreEntidadPagadora(),
            'rut' => ChileProvider::fake()->rutEmpresa(),
            'address' => fake()->streetAddress().', '.ChileProvider::fake()->comuna(),
            'billing_email' => 'facturacion@'.fake()->domainName(),
            'phone' => ChileProvider::fake()->telefonoChileno(),
        ];
    }

    public function sinRut(): static
    {
        return $this->state(['rut' => null]);
    }
}
