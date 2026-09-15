<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            RolesSeeder::class,
        ]);

        // Una cuenta por rol, con la clave igual al correo. Nunca en producción.
        if (app()->isLocal()) {
            $this->call(CuentasDePruebaSeeder::class);
        }
    }
}
