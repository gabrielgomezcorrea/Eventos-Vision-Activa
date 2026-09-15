<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fecha del evento. Hasta ahora las fechas solo vivían en las jornadas,
        // y un evento recién creado no tenía cuándo: el dato más básico que
        // pregunta cualquiera. Las jornadas se validan desde esta fecha en
        // adelante, así que sigue habiendo una sola fecha que manda.
        //
        // Nullable porque los eventos ya creados no la tienen; al editarlos se
        // completa.
        Schema::table('events', function (Blueprint $table) {
            $table->date('starts_on')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('starts_on');
        });
    }
};
