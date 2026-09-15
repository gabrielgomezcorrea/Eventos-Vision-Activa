<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            $table->string('name');            // "Jornada 1"
            $table->unsignedSmallInteger('position')->default(1);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->string('location')->nullable(); // sobrescribe el lugar del evento

            // capacity nulo = sin límite. reserved_seats es el contador que se
            // bloquea con lockForUpdate al confirmar una orden.
            $table->unsignedInteger('capacity')->nullable();
            $table->unsignedInteger('reserved_seats')->default(0);

            $table->timestamps();

            $table->unique(['event_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_sessions');
    }
};
