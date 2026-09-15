<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Qué tarifa se aplicó al confirmar y hasta cuándo regía.
        //
        // Es la evidencia de por qué esta orden pagó un valor y otra pagó otro.
        // Sin esto, cuando alguien reclame que "el precio era distinto" no hay
        // nada que mostrarle.
        Schema::table('orders', function (Blueprint $table) {
            $table->string('applied_tariff')->nullable()->after('total');
            $table->date('applied_tariff_until')->nullable()->after('applied_tariff');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['applied_tariff', 'applied_tariff_until']);
        });
    }
};
