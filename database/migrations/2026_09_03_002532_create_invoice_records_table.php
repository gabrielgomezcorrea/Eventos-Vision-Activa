<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // La factura se emite fuera del sistema, en el software contable de la
        // organización. Aquí solo se registra la evidencia para que la orden
        // tenga un expediente completo.
        Schema::create('invoice_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->string('document_type')->default('invoice'); // invoice | receipt | credit_note
            $table->string('number', 50);
            $table->date('issued_on');
            $table->unsignedBigInteger('amount')->default(0);

            // Archivo del documento, en el mismo disco privado que los
            // comprobantes: la base solo guarda metadata.
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->string('original_name')->nullable();
            $table->unsignedBigInteger('size')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->string('sent_to')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('registered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('registered_by_label')->nullable();

            $table->timestamps();

            // El mismo documento no se registra dos veces.
            $table->unique(['document_type', 'number']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_records');
    }
};
