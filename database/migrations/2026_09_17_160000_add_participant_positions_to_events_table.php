<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            // Null y no la lista completa: un evento que nunca eligió acepta
            // todos los cargos, y si mañana se agrega uno, lo acepta también.
            $table->json('participant_positions')->nullable()->after('program_form_fields');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('participant_positions');
        });
    }
};
