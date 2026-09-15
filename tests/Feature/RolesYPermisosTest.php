<?php

namespace Tests\Feature;

use App\Enums\Permiso;
use App\Enums\Rol;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolesYPermisosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
    }

    private function usuarioCon(Rol $rol): User
    {
        $user = User::factory()->create();
        $user->assignRole($rol->value);

        return $user->fresh();
    }

    public function test_se_crean_los_cuatro_roles(): void
    {
        foreach (Rol::cases() as $rol) {
            $this->assertDatabaseHas('roles', ['name' => $rol->value]);
        }
    }

    public function test_el_administrador_tiene_todos_los_permisos(): void
    {
        $admin = $this->usuarioCon(Rol::Administrador);

        foreach (Permiso::todos() as $permiso) {
            $this->assertTrue(
                $admin->can($permiso->value),
                "El administrador deberia poder {$permiso->value}",
            );
        }
    }

    public function test_acreditacion_no_accede_a_comprobantes_ni_facturas(): void
    {
        $acreditador = $this->usuarioCon(Rol::Acreditacion);

        $this->assertFalse($acreditador->can(Permiso::VerComprobantes->value));
        $this->assertFalse($acreditador->can(Permiso::ValidarPagos->value));
        $this->assertFalse($acreditador->can(Permiso::GestionarFacturas->value));
        $this->assertFalse($acreditador->can(Permiso::VerOrdenes->value));

        // Pero sí lo suyo.
        $this->assertTrue($acreditador->can(Permiso::AcreditarParticipantes->value));
    }

    public function test_solo_contabilidad_y_admin_validan_pagos(): void
    {
        $this->assertTrue($this->usuarioCon(Rol::Contabilidad)->can(Permiso::ValidarPagos->value));
        $this->assertTrue($this->usuarioCon(Rol::Administrador)->can(Permiso::ValidarPagos->value));

        $this->assertFalse($this->usuarioCon(Rol::Coordinacion)->can(Permiso::ValidarPagos->value));
        $this->assertFalse($this->usuarioCon(Rol::Acreditacion)->can(Permiso::ValidarPagos->value));
    }

    public function test_solo_el_administrador_gestiona_usuarios(): void
    {
        $this->assertTrue($this->usuarioCon(Rol::Administrador)->can(Permiso::GestionarUsuarios->value));

        foreach ([Rol::Coordinacion, Rol::Contabilidad, Rol::Acreditacion] as $rol) {
            $this->assertFalse($this->usuarioCon($rol)->can(Permiso::GestionarUsuarios->value));
        }
    }

    public function test_el_seeder_es_idempotente(): void
    {
        $this->seed(RolesSeeder::class);
        $this->seed(RolesSeeder::class);

        $this->assertSame(count(Rol::cases()), Role::count());
        $this->assertSame(count(Permiso::todos()), Permission::count());
    }
}
