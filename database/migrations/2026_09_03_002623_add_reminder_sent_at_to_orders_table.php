<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // El recordatorio se envía una sola vez por reserva. Sin esta marca
            // el comando programado lo mandaría en cada corrida.
            $table->timestamp('reminder_sent_at')->nullable()->after('reserved_until');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('reminder_sent_at');
        });
    }
};
