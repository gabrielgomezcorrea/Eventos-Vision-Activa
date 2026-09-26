<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Después de discount_codes (2026_09_25_213435): MySQL exige que la tabla referida exista.
            $table->foreignId('discount_code_id')->nullable()->after('discount_label')
                ->constrained('discount_codes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('discount_code_id');
        });
    }
};
