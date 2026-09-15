<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('establishment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('access_type_id')->constrained();

            $table->string('first_name');
            $table->string('last_name')->nullable();

            // El RUT es opcional a propósito: en inscripciones institucionales
            // muchas veces no viene, y exigirlo bloquearía el proceso.
            $table->string('rut', 20)->nullable()->index();

            $table->string('position')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable();

            $table->string('status')->default('registered')->index();

            // Precio congelado al confirmar la orden.
            $table->unsignedBigInteger('unit_price')->default(0);

            // Reemplazos: el participante saliente conserva su registro.
            $table->foreignId('replaced_by_id')->nullable()->constrained('participants')->nullOnDelete();
            $table->timestamp('replaced_at')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participants');
    }
};
