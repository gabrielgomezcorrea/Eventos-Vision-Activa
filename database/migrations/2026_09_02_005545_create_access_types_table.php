<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            $table->string('name');            // "Ambas jornadas"
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('position')->default(1);

            // Monto final que paga el cliente, en CLP entero. Sin decimales ni IVA.
            $table->unsignedBigInteger('price')->default(0);

            // Pulsera que corresponde a este acceso. Solo aplica a eventos presenciales.
            $table->string('wristband_label')->nullable();
            $table->string('wristband_color')->nullable(); // hex, ej. #F58A07

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_types');
    }
};
