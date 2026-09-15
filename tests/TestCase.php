<?php

namespace Tests;

use App\Enums\Rol;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use Spatie\Permission\Models\Role;

abstract class TestCase extends BaseTestCase
{
    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * Un usuario que puede entrar al panel: activo y con rol. Sin rol, el login
     * lo rechaza y una sesión abierta se cierra sola.
     *
     * @param  array<string, mixed>  $atributos
     */
    protected function usuarioConRol(Rol $rol = Rol::Administrador, array $atributos = []): User
    {
        if (Role::query()->doesntExist()) {
            $this->seed(RolesSeeder::class);
        }

        $usuario = User::factory()->create(['is_active' => true, ...$atributos]);
        $usuario->assignRole($rol->value);

        return $usuario->fresh();
    }
}
