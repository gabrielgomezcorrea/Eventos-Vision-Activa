<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->string('region')->nullable()->after('address');
            $table->string('commune')->nullable()->after('region');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn(['region', 'commune']);
        });
    }
};
