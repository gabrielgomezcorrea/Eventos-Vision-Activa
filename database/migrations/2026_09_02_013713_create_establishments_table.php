<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Establecimiento del que provienen los participantes. El RBD se ingresa
        // manualmente: no hay integración con la base oficial en el MVP.
        Schema::create('establishments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('rbd', 20)->nullable()->index();
            $table->string('address')->nullable();
            $table->string('commune')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('establishments');
    }
};
