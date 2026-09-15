<?php

namespace Tests\Feature;

use App\Enums\Permiso;
use App\Enums\Rol;
use App\Models\User;
use App\Support\MatrizDeRoles;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El Administrador es el rol sin límites: cualquier cosa que pueda hacer otro
 * rol, la puede hacer él. Si alguien agrega un permiso y lo asigna solo a
 * Contabilidad, esta prueba lo detiene.
 */
class PermisosDeRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_administrador_puede_todo_lo_que_pueden_los_demas(): void
    {
        $delAdmin = Rol::Administrador->permisos();

        foreach (Rol::cases() as $rol) {
            foreach ($rol->permisos() as $permiso) {
                $this->assertContains(
                    $permiso,
                    $delAdmin,
                    "El rol {$rol->label()} tiene «{$permiso->value}» y el Administrador no.",
                );
            }
        }

        $this->assertSame(count(Permiso::todos()), count($delAdmin));
    }

    public function test_un_usuario_administrador_pasa_todas_las_comprobaciones_del_sistema(): void
    {
        $this->seed(RolesSeeder::class);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Rol::Administrador->value);
        $admin = $admin->fresh();

        foreach (Permiso::todos() as $permiso) {
            $this->assertTrue(
                $admin->can($permiso->value),
                "El administrador no pudo «{$permiso->value}».",
            );
        }
    }

    public function test_la_tabla_de_roles_se_calcula_desde_los_permisos_reales(): void
    {
        $filas = MatrizDeRoles::filas();

        // Toda la columna del administrador en «sí»: es lo que dice la tabla
        // que se le muestra a quien crea un usuario.
        foreach ($filas as $fila) {
            $this->assertSame('si', $fila['marcas'][Rol::Administrador->value], $fila['que']);
        }

        $acreditar = collect($filas)->firstWhere('que', 'Escanear y acreditar en la puerta');

        $this->assertSame('si', $acreditar['marcas'][Rol::Acreditacion->value]);
        $this->assertSame('mirar', $acreditar['marcas'][Rol::Coordinacion->value]);
        $this->assertSame('no', $acreditar['marcas'][Rol::Contabilidad->value]);
    }
}
