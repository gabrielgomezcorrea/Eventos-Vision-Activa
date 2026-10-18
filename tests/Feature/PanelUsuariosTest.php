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

    public function test_un_usuario_nuevo_parte_con_los_avisos_de_su_rol(): void
    {
        $this->actingAs($this->usuarioConRol(Rol::Administrador));

        foreach ([[Rol::Coordinacion, 'flor@test.cl'], [Rol::Contabilidad, 'gloria@test.cl'], [Rol::Administrador, 'pablo@test.cl']] as [$rol, $correo]) {
            $this->post(route('usuarios.store'), ['name' => 'Persona Prueba', 'email' => $correo, 'password' => $correo, 'roles' => [$rol->value]])
                ->assertSessionHasNoErrors();
        }

        $flor = User::where('email', 'flor@test.cl')->firstOrFail();
        $this->assertTrue($flor->notify_program_requests);
        $this->assertTrue($flor->notify_confirmed_orders);
        $this->assertFalse($flor->notify_payment_proofs);

        $gloria = User::where('email', 'gloria@test.cl')->firstOrFail();
        $this->assertTrue($gloria->notify_payment_proofs);
        $this->assertFalse($gloria->notify_program_requests);

        $pablo = User::where('email', 'pablo@test.cl')->firstOrFail();
        $this->assertFalse($pablo->notify_program_requests || $pablo->notify_confirmed_orders || $pablo->notify_payment_proofs);
    }

    public function test_acreditacion_no_puede_encender_ningun_aviso(): void
    {
        $usuario = $this->usuarioConRol(Rol::Acreditacion);

        $this->actingAs($usuario)
            ->patch(route('profile.update'), ['notify_program_requests' => true])
            ->assertSessionHasErrors('notify_program_requests');
        $this->patch(route('profile.update'), ['notify_payment_proofs' => true])
            ->assertSessionHasErrors('notify_payment_proofs');

        $this->assertFalse($usuario->refresh()->notify_program_requests);
        $this->get(route('profile.edit'))->assertInertia(fn ($pagina) => $pagina->has('avisos', 0));
    }

    public function test_cada_uno_cambia_sus_avisos_desde_mi_perfil_sin_tocar_nombre_ni_correo(): void
    {
        $usuario = $this->usuarioConRol(Rol::Contabilidad);

        $this->actingAs($usuario)
            ->patch(route('profile.update'), ['notify_payment_proofs' => true])
            ->assertSessionHasNoErrors();

        $usuario->refresh();
        $this->assertTrue($usuario->notify_payment_proofs);
    }

    public function test_administracion_cambia_los_avisos_de_otro_y_coordinacion_no(): void
    {
        $otro = $this->usuarioConRol(Rol::Coordinacion, ['notify_program_requests' => true]);

        $this->actingAs($this->usuarioConRol(Rol::Coordinacion))
            ->patch(route('usuarios.update', $otro), ['notify_program_requests' => false])
            ->assertForbidden();
        $this->assertTrue($otro->refresh()->notify_program_requests);

        $this->actingAs($this->usuarioConRol(Rol::Administrador))
            ->patch(route('usuarios.update', $otro), ['notify_program_requests' => false, 'notify_payment_proofs' => true])
            ->assertSessionHasErrors('notify_payment_proofs');

        $this->patch(route('usuarios.update', $otro), ['notify_program_requests' => false])
            ->assertSessionHasNoErrors();
        $this->assertFalse($otro->refresh()->notify_program_requests);
    }
}
