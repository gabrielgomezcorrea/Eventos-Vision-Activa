<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->string('event_type', 60)->nullable();
            $table->string('display_title', 120)->nullable();
            $table->string('tagline', 160)->nullable();
            $table->string('organizer_name', 160)->nullable();
            $table->string('logo_disk')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('logo_mime')->nullable();
            $table->dateTime('logo_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn(['event_type', 'display_title', 'tagline', 'organizer_name', 'logo_disk', 'logo_path', 'logo_mime', 'logo_updated_at']);
        });
    }
};
