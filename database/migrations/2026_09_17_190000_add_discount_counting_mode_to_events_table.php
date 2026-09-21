<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // "Por colegio" por defecto: cambiar el modo no altera órdenes ya
            // confirmadas, así que empezar por el más conservador es seguro.
            $table->string('discount_counting_mode')->default('per_school')->after('reservation_duration_unit');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('discount_counting_mode');
        });
    }
};
