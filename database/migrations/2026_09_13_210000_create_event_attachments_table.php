<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Adjuntos del correo con el programa: hasta tres por evento.
        //
        // Antes era un solo PDF en una columna del evento. Pasa a tabla porque
        // en la práctica se manda más de un documento: el programa, la ficha de
        // los relatores, la carta de invitación.
        Schema::create('event_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);

            $table->timestamps();
            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_attachments');
    }
};
