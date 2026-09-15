<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Prefijo del folio, ej. SI en SI-2026-0048.
            $table->string('order_prefix', 10)->default('SI')->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('order_prefix');
        });
    }
};
