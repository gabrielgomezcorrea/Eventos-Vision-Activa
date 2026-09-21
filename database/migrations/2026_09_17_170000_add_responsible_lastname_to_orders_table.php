<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nullable a propósito: las órdenes ya creadas conservan su
     * `responsible_name` tal cual y quedan con apellidos vacío. No se parte
     * ningún nombre existente ni se adivina dónde empieza el apellido.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('responsible_lastname')->nullable()->after('responsible_name');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('responsible_lastname');
        });
    }
};
