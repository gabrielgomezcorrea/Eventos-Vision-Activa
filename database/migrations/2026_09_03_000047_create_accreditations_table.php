<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accreditations', function (Blueprint $table) {
            $table->id();

            // El índice único sobre el ticket es la garantía real de que nadie
            // se acredita dos veces. La validación en PHP no alcanza cuando dos
            // operadores escanean el mismo QR al mismo tiempo.
            $table->foreignId('ticket_id')->unique()->constrained()->cascadeOnDelete();

            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained()->cascadeOnDelete();

            // Quién entregó la pulsera. Queda para resolver dudas en terreno.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_label')->nullable();

            // Copia de la pulsera al momento de acreditar: si después se cambia
            // el color del acceso, el registro debe seguir diciendo qué se entregó.
            $table->string('wristband_label')->nullable();
            $table->string('wristband_color')->nullable();

            // 'qr' o 'manual', para saber cuánto se usó cada vía.
            $table->string('method', 20)->default('qr');

            $table->timestamp('accredited_at')->useCurrent();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['event_id', 'accredited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accreditations');
    }
};
