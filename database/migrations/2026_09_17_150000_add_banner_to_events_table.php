<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->string('banner_disk')->nullable()->after('description');
            $table->string('banner_path')->nullable()->after('banner_disk');
            $table->string('banner_mime')->nullable()->after('banner_path');
            // dateTime y no timestamp: MySQL le pone ON UPDATE CURRENT_TIMESTAMP
            // a la primera timestamp de la tabla y reescribe el valor solo.
            $table->dateTime('banner_updated_at')->nullable()->after('banner_mime');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn(['banner_disk', 'banner_path', 'banner_mime', 'banner_updated_at']);
        });
    }
};
