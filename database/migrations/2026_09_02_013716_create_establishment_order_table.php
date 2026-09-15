<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Una orden institucional puede agrupar varios establecimientos.
        Schema::create('establishment_order', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('establishment_id')->constrained()->cascadeOnDelete();

            $table->unique(['order_id', 'establishment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('establishment_order');
    }
};
