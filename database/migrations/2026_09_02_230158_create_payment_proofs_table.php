<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Solo metadata: el archivo vive en el disco privado. Guardar binarios
        // en la base infla los respaldos y ata el dominio al almacenamiento.
        Schema::create('payment_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();

            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size')->default(0);

            // Permite detectar el mismo archivo subido dos veces.
            $table->string('hash', 64)->nullable()->index();

            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('uploaded_by_label')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_proofs');
    }
};
