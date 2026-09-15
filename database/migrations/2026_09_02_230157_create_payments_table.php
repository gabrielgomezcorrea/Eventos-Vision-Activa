<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // El MVP solo usa transferencia manual, pero el modelo ya distingue
            // el medio y deja espacio para la referencia de un proveedor online.
            $table->string('method')->default('bank_transfer');
            $table->string('provider')->nullable();
            $table->string('provider_reference')->nullable();

            $table->string('status')->default('in_review')->index();

            // Datos declarados por quien pagó.
            $table->unsignedBigInteger('amount')->default(0);
            $table->date('paid_on')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('payer_name')->nullable();
            $table->string('payer_rut', 20)->nullable();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();

            // Quién lo declaró. Nulo cuando lo carga el cliente por magic link;
            // con usuario cuando lo carga Coordinación por un correo recibido.
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('submitted_by_label')->nullable();

            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
