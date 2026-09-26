<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            // Se guarda normalizado (mayúsculas, sin guiones ni espacios).
            $table->string('code', 20);
            $table->string('type')->default('percent');
            $table->unsignedInteger('value');
            $table->unsignedInteger('max_people');
            $table->unsignedInteger('used_people')->default(0);
            // dateTime y no timestamp: MySQL le pone ON UPDATE CURRENT_TIMESTAMP
            // a la primera timestamp de la tabla y reescribe el valor solo.
            $table->dateTime('expires_at');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['event_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_codes');
    }
};
