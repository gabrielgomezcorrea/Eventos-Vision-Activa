<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_type_discount_code', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('discount_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('access_type_id')->constrained()->cascadeOnDelete();
            // Precio final por persona con este código, en CLP.
            $table->unsignedInteger('price');
            $table->timestamps();

            $table->unique(['discount_code_id', 'access_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_type_discount_code');
    }
};
