<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `used_people` contaba participantes; desde ahora cuenta personas (correos
     * de solicitante distintos) con una inscripción viva que usa el código.
     */
    public function up(): void
    {
        DB::table('discount_codes')->orderBy('id')->each(function (object $codigo): void {
            $personas = DB::table('orders')
                ->where('discount_code_id', $codigo->id)
                ->whereIn('status', ['reserved', 'completed'])
                ->distinct()
                ->count(DB::raw('lower(responsible_email)'));

            DB::table('discount_codes')->where('id', $codigo->id)->update(['used_people' => $personas]);
        });
    }

    public function down(): void
    {
        // Sin vuelta atrás: el conteo anterior por participante no se reconstruye.
    }
};
