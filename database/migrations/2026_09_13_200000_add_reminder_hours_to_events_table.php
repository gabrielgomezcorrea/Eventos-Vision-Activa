<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cuántas horas antes del vencimiento se avisa, por evento.
        //
        // Un curso con reserva de tres días y un seminario con reserva de dos
        // semanas no se avisan con la misma antelación: en el primero, 48 horas
        // antes es casi el mismo día de la inscripción.
        //
        // Nulo: se usa el valor por defecto del comando.
        Schema::table('events', function (Blueprint $table) {
            $table->unsignedSmallInteger('reminder_hours_before')->nullable()->after('reservation_duration_unit');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('reminder_hours_before');
        });
    }
};
