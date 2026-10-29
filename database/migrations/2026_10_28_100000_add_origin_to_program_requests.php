<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('program_requests', function (Blueprint $table) {
            $table->string('origin', 20)->default('form')->after('event_id');
            $table->string('first_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('program_requests')->whereNull('first_name')->update(['first_name' => '']);

        Schema::table('program_requests', function (Blueprint $table) {
            $table->dropColumn('origin');
            $table->string('first_name')->nullable(false)->change();
        });
    }
};
