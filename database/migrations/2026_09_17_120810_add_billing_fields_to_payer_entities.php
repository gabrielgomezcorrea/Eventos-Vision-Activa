<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Commune: the invoice needs it from the payer.
     *
     * Phones already stored as +569... are left untouched on purpose: production
     * data is not rewritten. They are normalized when read (export, emails) and
     * when saved again.
     */
    public function up(): void
    {
        Schema::table('payer_entities', function (Blueprint $table) {
            $table->string('commune')->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('payer_entities', function (Blueprint $table) {
            $table->dropColumn('commune');
        });
    }
};
