<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payer_entities', function (Blueprint $table) {
            $table->string('business_activity', 255)->nullable();
            $table->string('billing_reference', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payer_entities', function (Blueprint $table) {
            $table->dropColumn(['business_activity', 'billing_reference']);
        });
    }
};
