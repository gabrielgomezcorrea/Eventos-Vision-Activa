<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Historial de decisiones de Contabilidad. Solo se agrega: una
        // aprobación posterior no borra la observación que hubo antes.
        Schema::create('payment_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_label')->nullable();

            $table->string('action')->index();   // approved | observed | rejected
            $table->text('comment')->nullable();

            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reviews');
    }
};
