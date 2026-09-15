<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Models\BankAccount;
use App\Models\Event;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quién entra al sistema y quién toca lo que ven los clientes para pagar.
 */
class PanelConfiguracionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
    }

    private function cuenta(): BankAccount
    {
        return BankAccount::create([
            'label' => 'Cuenta corriente BCI',
            'holder_name' => 'Empresa ATE SpA',
            'bank_name' => 'BCI',
            'account_number' => '12345678',
        ]);
    }

    public function test_un_usuario_desactivado_no_puede_entrar(): void
    {
        $usuario = $this->usuarioConRol(Rol::Coordinacion, ['is_active' => false]);

        $this->post(route('login.store'), ['email' => $usuario->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_un_usuario_sin_rol_no_puede_entrar(): void
    {
        $usuario = User::factory()->create(['is_active' => true]);

        $this->post(route('login.store'), ['email' => $usuario->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_una_sesion_abierta_se_cierra_al_desactivar_al_usuario(): void
    {
        $usuario = $this->usuarioConRol(Rol::Coordinacion);

        $this->actingAs($usuario)->get(route('dashboard'))->assertOk();

        $usuario->update(['is_active' => false]);

        $this->actingAs($usuario->fresh())->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_cada_uno_cambia_su_perfil_pero_no_borra_su_cuenta(): void
    {
        $usuario = $this->usuarioConRol(Rol::Coordinacion);
        $otro = $this->usuarioConRol(Rol::Contabilidad);

        $this->actingAs($usuario)
            ->patch(route('profile.update'), ['name' => $usuario->name, 'email' => $otro->email])
            ->assertSessionHasErrors('email');

        $this->actingAs($usuario)
            ->patch(route('profile.update'), ['name' => 'Otro Nombre', 'email' => 'nuevo@test.cl'])
            ->assertSessionHasNoErrors();

        $this->assertSame('nuevo@test.cl', $usuario->fresh()->email);

        $this->actingAs($usuario)->delete('/settings/profile', ['password' => 'password'])->assertMethodNotAllowed();
        $this->assertNotNull($usuario->fresh());
    }

    public function test_solo_administracion_ve_las_cuentas_bancarias(): void
    {
        $cuenta = $this->cuenta();

        foreach ([Rol::Coordinacion, Rol::Contabilidad, Rol::Acreditacion] as $rol) {
            $this->actingAs($this->usuarioConRol($rol))
                ->get(route('cuentas-bancarias.index'))
                ->assertForbidden();
        }

        $this->actingAs($this->usuarioConRol(Rol::Administrador));
        $this->get(route('cuentas-bancarias.index'))->assertOk();
        $this->get(route('cuentas-bancarias.show', $cuenta))->assertOk();
    }

    public function test_una_cuenta_que_usa_un_evento_no_se_elimina(): void
    {
        $cuenta = $this->cuenta();
        Event::create(['name' => 'Seminario 2026', 'slug' => 'seminario-2026', 'bank_account_id' => $cuenta->id]);

        $this->actingAs($this->usuarioConRol(Rol::Administrador))
            ->delete(route('cuentas-bancarias.destroy', $cuenta));

        $this->assertNotNull($cuenta->fresh());
    }

    public function test_administracion_crea_una_cuenta(): void
    {
        $this->actingAs($this->usuarioConRol(Rol::Administrador))
            ->post(route('cuentas-bancarias.store'), [
                'label' => 'Cuenta vista',
                'holder_name' => 'Empresa ATE SpA',
                'bank_name' => 'BancoEstado',
                'account_number' => '987654',
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('bank_accounts', ['label' => 'Cuenta vista', 'is_active' => true]);
    }
}
