<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas bancarias de la empresa, configuradas una vez y reutilizadas.
 *
 * Antes cada evento repetía titular, RUT, banco y número. Con dos o tres
 * eventos al año eso significa volver a escribir los mismos datos, y basta un
 * dígito mal copiado para que un cliente transfiera a una cuenta que no existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('label');
            $table->string('holder_name');
            $table->string('holder_rut', 20)->nullable();
            $table->string('bank_name');
            $table->string('account_type')->nullable();
            $table->string('account_number');
            $table->string('email')->nullable();
            $table->text('payment_instructions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
