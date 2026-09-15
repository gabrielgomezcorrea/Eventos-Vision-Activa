<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ajustes globales del sistema, en clave/valor.
        //
        // Hoy son el correo y el teléfono de ayuda que aparecen al pie de todos
        // los correos. Van en base y no en `.env` porque los cambia
        // Administración desde la pantalla, no alguien con acceso al servidor.
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
