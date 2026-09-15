<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Consentimiento de comunicaciones, separado del de la solicitud.
        //
        // `consented_at` respalda el envío del programa, que es lo que la
        // persona vino a pedir. Usar ese mismo correo para campañas es otra
        // finalidad y necesita su propio permiso: este campo es el que decide
        // qué contactos se pueden usar para eso.
        //
        // Instante y no booleano, igual que el otro: importa cuándo lo dio.
        Schema::table('program_requests', function (Blueprint $table) {
            $table->timestamp('marketing_consented_at')->nullable()->after('consented_at');
        });
    }

    public function down(): void
    {
        Schema::table('program_requests', function (Blueprint $table) {
            $table->dropColumn('marketing_consented_at');
        });
    }
};
