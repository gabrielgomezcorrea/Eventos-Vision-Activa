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
            $table->unsignedInteger('program_sent_count')->default(0);
        });

        Schema::table('magic_links', function (Blueprint $table) {
            $table->foreignId('program_request_id')->nullable()->constrained()->nullOnDelete();
            $table->text('token_encrypted')->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('program_request_id')->nullable()->constrained()->nullOnDelete();
        });

        $this->rellenar();
    }

    /**
     * Une cada orden a la solicitud más reciente del mismo evento y correo, y
     * cuenta como un envío los programas ya mandados. No borra ni cambia nada
     * existente.
     */
    public function rellenar(): void
    {
        DB::table('program_requests')
            ->whereNotNull('program_sent_at')
            ->where('program_sent_count', 0)
            ->update(['program_sent_count' => 1]);

        DB::table('orders')
            ->whereNull('program_request_id')
            ->whereNotNull('responsible_email')
            ->chunkById(200, function ($orders): void {
                foreach ($orders as $order) {
                    $solicitudId = DB::table('program_requests')
                        ->where('event_id', $order->event_id)
                        ->where('email', mb_strtolower(trim($order->responsible_email)))
                        ->max('id');

                    if ($solicitudId !== null) {
                        DB::table('orders')->where('id', $order->id)->update(['program_request_id' => $solicitudId]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('program_request_id');
        });

        Schema::table('magic_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('program_request_id');
            $table->dropColumn('token_encrypted');
        });

        Schema::table('program_requests', function (Blueprint $table) {
            $table->dropColumn('program_sent_count');
        });
    }
};
