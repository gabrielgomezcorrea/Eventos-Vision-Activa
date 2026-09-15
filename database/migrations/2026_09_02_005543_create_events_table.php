<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();

            // Identidad
            $table->string('name');
            $table->string('slug')->unique(); // usado en la URL pública del formulario
            $table->text('description')->nullable();
            $table->string('status')->default('draft')->index();
            $table->string('modality')->default('presencial');

            // Lugar y fechas de referencia. Las fechas reales viven en las jornadas.
            $table->string('location')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();

            // Plazo de reserva: valor + unidad, para poder pasar a días hábiles sin migración.
            $table->unsignedSmallInteger('reservation_duration_value')->default(3);
            $table->string('reservation_duration_unit')->default('calendar_days');

            // Fecha límite para solicitar reemplazo de participantes.
            $table->date('replacement_deadline')->nullable();

            // Datos bancarios para la transferencia. Configurables por evento.
            $table->string('bank_holder_name')->nullable();
            $table->string('bank_holder_rut')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_type')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('bank_email')->nullable();
            $table->text('payment_instructions')->nullable();

            // Contacto operativo mostrado al cliente y en los correos.
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
