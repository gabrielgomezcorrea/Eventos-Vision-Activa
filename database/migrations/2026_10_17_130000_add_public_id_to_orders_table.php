<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Elige una inscripción dentro de su conjunto en la URL (`?inscripcion=`).
        // Nunca autoriza: el token sigue mandando.
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('public_id', 26)->nullable()->unique()->after('id');
        });

        DB::table('orders')->whereNull('public_id')->orderBy('id')->each(function (object $orden): void {
            DB::table('orders')->where('id', $orden->id)->update(['public_id' => (string) Str::ulid()]);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
