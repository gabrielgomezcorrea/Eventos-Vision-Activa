<?php

use App\Enums\Rol;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('notify_program_requests')->default(false);
            $table->boolean('notify_confirmed_orders')->default(false);
            $table->boolean('notify_payment_proofs')->default(false);
        });

        // Los usuarios que ya existen parten con los avisos de su rol.
        foreach ([Rol::Coordinacion, Rol::Contabilidad] as $rol) {
            $ids = DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('roles.name', $rol->value)
                ->where('model_has_roles.model_type', 'App\\Models\\User')
                ->pluck('model_has_roles.model_id');

            DB::table('users')->whereIn('id', $ids)->update(array_fill_keys($rol->avisosPorDefecto(), true));
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['notify_program_requests', 'notify_confirmed_orders', 'notify_payment_proofs']);
        });
    }
};
