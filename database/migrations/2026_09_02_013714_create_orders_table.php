<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            // Número visible, ej. SI-2026-0048. Nulo mientras es borrador:
            // solo se asigna al confirmar, para no consumir folios en abandonos.
            $table->string('number', 30)->nullable()->unique();

            // Ciclo de vida y situación financiera son campos distintos.
            // Un pago observado no cambia el ciclo de vida de la orden.
            $table->string('status')->default('draft')->index();
            $table->string('payment_status')->default('pending')->index();

            $table->string('kind')->default('institutional'); // institutional | individual

            // Responsable de la inscripción. Es contacto operativo, no
            // necesariamente participante ni representante legal.
            $table->string('responsible_name');
            $table->string('responsible_email')->index();
            $table->string('responsible_position')->nullable();
            $table->string('responsible_phone')->nullable();
            $table->string('responsible_institution')->nullable();

            $table->foreignId('payer_entity_id')->nullable()->constrained()->nullOnDelete();

            // Totales congelados al confirmar. CLP entero.
            $table->unsignedBigInteger('total')->default(0);

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('reserved_until')->nullable()->index();
            $table->timestamp('cancelled_at')->nullable();

            $table->text('internal_notes')->nullable();

            $table->timestamps();

            $table->index(['event_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
