<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Qué jornadas consume cada tipo de acceso y cuántos cupos toma en cada una.
        // Es lo que evita la sobreventa cuando un acceso cubre varias jornadas.
        Schema::create('access_type_event_session', function (Blueprint $table) {
            $table->id();
            $table->foreignId('access_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('seats')->default(1);

            $table->unique(['access_type_id', 'event_session_id'], 'access_type_session_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_type_event_session');
    }
};
