<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contact becomes per event only: each event is handled by different people.
     * Events without their own contact inherit the global one before it goes
     * away, and the rest get the default contact, so no email loses its footer.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('contact_role')->nullable()->after('contact_name');
            $table->string('contact_organization')->nullable()->after('contact_role');
            $table->string('contact_whatsapp')->nullable()->after('contact_phone');
        });

        $global = DB::table('settings')->whereIn('key', ['contacto_email', 'contacto_telefono'])->pluck('value', 'key');

        if (filled($global['contacto_email'] ?? null)) {
            DB::table('events')->whereNull('contact_email')->update(['contact_email' => $global['contacto_email']]);
        }

        if (filled($global['contacto_telefono'] ?? null)) {
            DB::table('events')->whereNull('contact_phone')->update(['contact_phone' => $global['contacto_telefono']]);
        }

        DB::table('settings')->whereIn('key', ['contacto_email', 'contacto_telefono'])->delete();

        // Still without contact: the default one, so every email has a footer.
        DB::table('events')->whereNull('contact_email')->whereNull('contact_phone')
            ->update(config('contacto_eventos'));
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['contact_role', 'contact_organization', 'contact_whatsapp']);
        });
    }
};
