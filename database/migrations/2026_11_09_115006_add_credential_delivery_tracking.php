<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credentials always go out when payment completes (09/11/2026): to the
 * responsible person and to each participant with an email. These columns
 * record where they went so the panel shows it instead of guessing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('emailed_to')->nullable()->after('emailed_at');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('credentials_sent_to')->nullable();
            $table->dateTime('credentials_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('emailed_to');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['credentials_sent_to', 'credentials_sent_at']);
        });
    }
};
