<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Schools pay in parts, weeks apart. Each proof now carries its own
     * billing instructions, and each invoice can point at the payment it
     * covers (nullable: invoices registered before this have none).
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->text('billing_notes')->nullable()->after('notes');
        });

        Schema::table('invoice_records', function (Blueprint $table) {
            $table->foreignId('payment_id')->nullable()->after('order_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('billing_notes');
        });
    }
};
