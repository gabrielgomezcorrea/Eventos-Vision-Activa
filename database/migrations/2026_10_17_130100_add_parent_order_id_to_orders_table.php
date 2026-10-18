<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // De qué inscripción confirmada nació una "inscripción adicional" (más
        // participantes del mismo colegio, con su propio pago).
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('parent_order_id')->nullable()->after('group_id')->constrained('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_order_id');
        });
    }
};
