<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Definición de los campos del formulario público de captación.
            // Null = usar el conjunto base definido en ProgramFormField::porDefecto().
            $table->json('program_form_fields')->nullable()->after('description');

            // Contenido del correo con el programa.
            $table->string('program_file_path')->nullable()->after('program_form_fields');
            $table->string('program_file_name')->nullable()->after('program_file_path');
            $table->text('program_email_intro')->nullable()->after('program_file_name');

            // Texto del consentimiento y encabezado del formulario embebido.
            $table->string('form_heading')->nullable()->after('program_email_intro');
            $table->text('form_intro')->nullable()->after('form_heading');
            $table->text('consent_text')->nullable()->after('form_intro');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn([
                'program_form_fields', 'program_file_path', 'program_file_name',
                'program_email_intro', 'form_heading', 'form_intro', 'consent_text',
            ]);
        });
    }
};
