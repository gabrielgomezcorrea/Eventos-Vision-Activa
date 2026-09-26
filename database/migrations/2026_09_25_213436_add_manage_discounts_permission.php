<?php

use App\Enums\Permiso;
use App\Enums\Rol;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * El despliegue no corre RolesSeeder: sin esta migración el permiso no existiría
 * en producción y ni Administración vería los códigos de descuento.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permiso = Permission::findOrCreate(Permiso::GestionarDescuentos->value, 'web');

        Role::where('name', Rol::Administrador->value)->where('guard_name', 'web')->first()?->givePermissionTo($permiso);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', Permiso::GestionarDescuentos->value)->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
