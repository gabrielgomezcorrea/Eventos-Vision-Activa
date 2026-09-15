<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // De dónde llegó la solicitud, con los parámetros de campaña.
        //
        // Hasta ahora solo se guardaba el `referer`, que el navegador interno
        // de Instagram y Facebook suele borrar: con publicidad pagada eso deja
        // sin respuesta la única pregunta que importa, qué campaña trajo a esta
        // persona.
        //
        // Columnas propias y no JSON: se filtra y se agrupa por campaña, y las
        // consultas tienen que funcionar igual en SQLite y en MySQL.
        Schema::table('program_requests', function (Blueprint $table) {
            $table->string('utm_source')->nullable()->after('source_url');
            $table->string('utm_medium')->nullable()->after('utm_source');
            $table->string('utm_campaign')->nullable()->after('utm_medium');

            $table->index('utm_campaign');
        });
    }

    public function down(): void
    {
        Schema::table('program_requests', function (Blueprint $table) {
            $table->dropIndex(['utm_campaign']);
            $table->dropColumn(['utm_source', 'utm_medium', 'utm_campaign']);
        });
    }
};
