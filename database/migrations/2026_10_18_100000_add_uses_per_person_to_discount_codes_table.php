<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cuántas veces usa el código cada persona (correo del solicitante):
        // en todos sus recorridos del evento o en uno solo. El tope
        // (`max_people`) pasa a contar personas, no participantes.
        Schema::table('discount_codes', function (Blueprint $table): void {
            $table->string('uses_per_person', 20)->default('unlimited')->after('max_people');
        });
    }

    public function down(): void
    {
        Schema::table('discount_codes', function (Blueprint $table): void {
            $table->dropColumn('uses_per_person');
        });
    }
};
