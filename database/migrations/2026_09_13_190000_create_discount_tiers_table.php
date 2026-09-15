<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tramos de descuento por cantidad de participantes, por evento.
        //
        // Se aplican sobre la orden completa y no por participante: es como se
        // negocia por teléfono ("desde cinco personas les hago un 10%") y es lo
        // que el cliente entiende al leer el correo.
        Schema::create('discount_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('min_participants');
            $table->string('type')->default('percent'); // percent | amount
            $table->unsignedBigInteger('value');
            $table->timestamps();

            // Un solo tramo por cantidad: dos reglas para "desde 5" obligarían
            // a decidir cuál gana, y esa decisión no existe.
            $table->unique(['event_id', 'min_participants']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_tiers');
    }
};
