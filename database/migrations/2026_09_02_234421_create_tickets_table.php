<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained()->cascadeOnDelete();

            // Código alfanumérico de respaldo, legible y dictable en voz alta
            // cuando el QR no se puede escanear.
            $table->string('code', 20)->unique();

            // El token es la credencial. Se guarda cifrado para poder volver a
            // dibujar el QR, y su hash aparte con índice único para poder
            // buscarlo en un escaneo sin recorrer la tabla.
            $table->text('token');
            $table->string('token_hash', 64)->unique();

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('emailed_at')->nullable();

            // Un reemplazo de participante invalida el ticket anterior.
            $table->timestamp('revoked_at')->nullable()->index();
            $table->string('revoked_reason')->nullable();

            $table->timestamps();

            // Un solo ticket vigente por participante.
            $table->unique(['participant_id', 'revoked_at'], 'ticket_participante_unico');
            $table->index(['event_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
