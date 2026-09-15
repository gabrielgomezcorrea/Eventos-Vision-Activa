<?php

namespace Database\Seeders;

use App\Enums\Rol;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Cuentas de prueba, una por rol. La clave es el mismo correo.
 *
 * Solo para desarrollo: no correr en producción.
 */
class CuentasDePruebaSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesSeeder::class);

        $nombres = [
            Rol::Administrador->value => 'Admin User',
            Rol::Coordinacion->value => 'Coordinacion User',
            Rol::Contabilidad->value => 'Contabilidad User',
            Rol::Acreditacion->value => 'Acreditacion User',
        ];

        foreach (Rol::cases() as $rol) {
            // Patrón fijo de las cuentas de prueba: `rol@test.cl`, y el
            // administrador es `admin@test.cl`, no `administrador@test.cl`.
            $correo = ($rol === Rol::Administrador ? 'admin' : $rol->value).'@test.cl';

            $usuario = User::updateOrCreate(
                ['email' => $correo],
                [
                    'name' => $nombres[$rol->value],
                    'password' => $correo,
                    'is_active' => true,
                ],
            );

            $usuario->syncRoles([$rol->value]);

            $this->command->line("  {$correo}  ·  clave: {$correo}  ·  {$rol->label()}");
        }

        $this->command->info('Cuentas de prueba listas.');
    }
}
