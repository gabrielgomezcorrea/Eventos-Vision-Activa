<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Precio anticipado con fecha de corte.
        //
        // Dos precios y una fecha, no un calendario de tramos: el caso real es
        // "hasta el 30 vale 80.000 y después 160.000". Un calendario agrega
        // pantalla que mantener y formas nuevas de configurarlo mal.
        Schema::table('access_types', function (Blueprint $table) {
            $table->unsignedBigInteger('early_price')->nullable()->after('price');
            $table->date('early_until')->nullable()->after('early_price');
        });
    }

    public function down(): void
    {
        Schema::table('access_types', function (Blueprint $table) {
            $table->dropColumn(['early_price', 'early_until']);
        });
    }
};
