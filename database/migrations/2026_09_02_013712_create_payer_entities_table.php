<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Entidad a cuyo nombre se emite la factura. Puede ser distinta del
        // establecimiento y se reutiliza entre órdenes cuando el RUT coincide.
        Schema::create('payer_entities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('rut', 20)->nullable()->index();
            $table->string('address')->nullable();
            $table->string('billing_email')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payer_entities');
    }
};
