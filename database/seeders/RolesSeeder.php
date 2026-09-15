<?php

namespace Database\Seeders;

use App\Enums\Permiso;
use App\Enums\Rol;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Siembra roles y permisos. Es idempotente: puede correrse tras agregar
 * un permiso nuevo sin duplicar ni perder asignaciones manuales.
 */
class RolesSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permiso::todos() as $permiso) {
            Permission::findOrCreate($permiso->value, 'web');
        }

        // Spatie deja en cache la lista de permisos que existia al primer acceso.
        // Sin este segundo olvido, syncPermissions no ve los recien creados y
        // revienta con PermissionDoesNotExist en una base limpia.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Rol::cases() as $rol) {
            $role = Role::findOrCreate($rol->value, 'web');

            $role->syncPermissions(
                collect($rol->permisos())->map(fn (Permiso $p) => $p->value)->all()
            );
        }
    }
}
