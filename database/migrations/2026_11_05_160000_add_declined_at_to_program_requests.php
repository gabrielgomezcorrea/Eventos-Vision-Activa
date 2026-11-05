<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «No participará»: la persona dijo que no va. Sale de pendientes sin borrarse,
 * porque sirve para próximos eventos. La nota que lo marcó guarda su tipo para
 * distinguirse de las notas comunes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('program_requests', function (Blueprint $table) {
            $table->dateTime('declined_at')->nullable()->after('converted_at');
        });

        Schema::table('program_request_notes', function (Blueprint $table) {
            $table->string('kind', 20)->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('program_requests', function (Blueprint $table) {
            $table->dropColumn('declined_at');
        });

        Schema::table('program_request_notes', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
