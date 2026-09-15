<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quién administra al equipo, y que nadie se deje a sí mismo sin acceso.
 */
class PanelUsuariosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
    }

    public function test_solo_administracion_administra_usuarios(): void
    {
        foreach ([Rol::Coordinacion, Rol::Contabilidad, Rol::Acreditacion] as $rol) {
            $this->actingAs($this->usuarioConRol($rol))
                ->get(route('usuarios.index'))
                ->assertForbidden();
        }

        $this->actingAs($this->usuarioConRol(Rol::Administrador))
            ->get(route('usuarios.index'))
            ->assertOk();
    }

    public function test_un_usuario_creado_con_rol_puede_entrar(): void
    {
        $this->actingAs($this->usuarioConRol(Rol::Administrador))
            ->post(route('usuarios.store'), [
                'name' => 'Paula Contreras',
                'email' => 'paula@test.cl',
                'password' => 'paula@test.cl',
                'roles' => [Rol::Contabilidad->value],
            ])
            ->assertSessionHasNoErrors();

        auth()->logout();

        $this->post(route('login.store'), ['email' => 'paula@test.cl', 'password' => 'paula@test.cl'])
            ->assertRedirect(route('dashboard'));

        $this->assertTrue(User::where('email', 'paula@test.cl')->firstOrFail()->hasRole(Rol::Contabilidad->value));
    }

    public function test_no_se_crea_un_usuario_sin_rol(): void
    {
        $this->actingAs($this->usuarioConRol(Rol::Administrador))
            ->post(route('usuarios.store'), [
                'name' => 'Sin Rol',
                'email' => 'sinrol@test.cl',
                'password' => 'sinrol@test.cl',
                'roles' => [],
            ])
            ->assertSessionHasErrors('roles');

        $this->assertDatabaseMissing('users', ['email' => 'sinrol@test.cl']);
    }

    public function test_nadie_se_deja_a_si_mismo_sin_acceso(): void
    {
        $admin = $this->usuarioConRol(Rol::Administrador);

        $this->actingAs($admin)
            ->patch(route('usuarios.update', $admin), ['is_active' => false])
            ->assertSessionHasErrors('is_active');

        $this->actingAs($admin)
            ->patch(route('usuarios.update', $admin), ['roles' => [Rol::Coordinacion->value]])
            ->assertSessionHasErrors('roles');

        $this->actingAs($admin)->delete(route('usuarios.destroy', $admin))->assertForbidden();

        $admin->refresh();
        $this->assertTrue($admin->is_active);
        $this->assertTrue($admin->hasRole(Rol::Administrador->value));
    }

    public function test_asignar_una_contrasena_nueva_permite_entrar_con_ella(): void
    {
        $persona = $this->usuarioConRol(Rol::Coordinacion);

        $this->actingAs($this->usuarioConRol(Rol::Administrador))
            ->patch(route('usuarios.update', $persona), ['password' => 'nueva-clave-1'])
            ->assertSessionHasNoErrors();

        auth()->logout();

        $this->post(route('login.store'), ['email' => $persona->email, 'password' => 'nueva-clave-1'])
            ->assertRedirect(route('dashboard'));
    }
}
