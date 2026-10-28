<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('discount_code_email')->nullable()->index();
        });

        DB::table('orders')
            ->whereNotNull('discount_code_id')
            ->chunkById(200, function ($orders): void {
                foreach ($orders as $order) {
                    DB::table('orders')->where('id', $order->id)->update([
                        'discount_code_email' => mb_strtolower(trim($order->responsible_email)),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['discount_code_email']);
            $table->dropColumn('discount_code_email');
        });
    }
};
