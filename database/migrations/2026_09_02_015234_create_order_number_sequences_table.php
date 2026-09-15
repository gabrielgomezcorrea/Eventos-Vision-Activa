<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Correlativo de folios por evento y año. Existe como tabla propia para
        // poder bloquear una sola fila al asignar el número, en vez de derivarlo
        // de un max() sobre orders, que produce folios repetidos bajo carga.
        Schema::create('order_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);

            $table->unique(['event_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_number_sequences');
    }
};
