<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Quién. Nulo cuando la acción la ejecuta el sistema (comandos programados)
            // o un cliente externo autenticado por magic link.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_label')->nullable(); // "Sistema", correo del cliente, etc.

            // Sobre qué entidad.
            $table->morphs('auditable');

            // Qué pasó.
            $table->string('action')->index();          // ej. 'pago.aprobado'
            $table->string('old_status')->nullable();
            $table->string('new_status')->nullable();
            $table->text('comment')->nullable();
            $table->json('properties')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
