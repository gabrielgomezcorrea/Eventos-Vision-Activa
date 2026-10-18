<?php

use App\Enums\Permiso;
use App\Enums\Rol;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * El despliegue no corre RolesSeeder: sin esta migración el permiso no existiría
 * en producción y nadie vería el reporte de acreditación.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permiso = Permission::findOrCreate(Permiso::VerReporteAcreditacion->value, 'web');

        foreach ([Rol::Administrador, Rol::Coordinacion, Rol::Acreditacion] as $rol) {
            Role::where('name', $rol->value)->where('guard_name', 'web')->first()?->givePermissionTo($permiso);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', Permiso::VerReporteAcreditacion->value)->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
