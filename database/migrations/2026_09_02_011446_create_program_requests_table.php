<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            // Campos base del formulario de captación.
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('email')->index();
            $table->string('position')->nullable();     // cargo
            $table->string('institution')->nullable();
            $table->string('phone')->nullable();

            // Jornada de interés. Se guarda la referencia y también la etiqueta,
            // para que el registro siga siendo legible si el acceso se renombra.
            $table->foreignId('access_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('interest_label')->nullable();

            // Campos adicionales configurados por evento.
            $table->json('extra')->nullable();

            // Consentimiento: se guarda el instante, no un booleano.
            $table->timestamp('consented_at')->nullable();

            // Seguimiento interno.
            $table->timestamp('program_sent_at')->nullable();
            $table->timestamp('converted_at')->nullable(); // derivó en inscripción
            $table->text('internal_notes')->nullable();

            // Trazabilidad del origen.
            $table->string('source_url')->nullable();
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            $table->index(['event_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_requests');
    }
};
