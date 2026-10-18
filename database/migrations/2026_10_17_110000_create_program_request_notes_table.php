<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_request_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('program_request_id')->constrained()->cascadeOnDelete();
            // La nota sobrevive si se borra al usuario; se pierde solo su nombre.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_request_notes');
    }
};
