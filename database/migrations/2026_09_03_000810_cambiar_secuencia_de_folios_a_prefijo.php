<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La secuencia de folios pasa de ser por evento a ser por prefijo.
 *
 * `orders.number` es único en toda la tabla, así que dos eventos que comparten
 * el prefijo "SI" generaban el mismo folio y chocaban. Con la secuencia por
 * prefijo, los eventos que comparten prefijo comparten correlativo, y quien
 * quiera una serie aparte solo cambia el prefijo del evento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('order_number_sequences');

        Schema::create('order_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 10);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);

            $table->unique(['prefix', 'year']);
        });

        $this->recuperarCorrelativosExistentes();
    }

    public function down(): void
    {
        Schema::dropIfExists('order_number_sequences');
    }

    /**
     * Reconstruye el correlativo desde los folios ya emitidos, para que la
     * numeración continúe donde iba y no vuelva a empezar en 1.
     */
    private function recuperarCorrelativosExistentes(): void
    {
        $maximos = [];

        foreach (DB::table('orders')->whereNotNull('number')->pluck('number') as $numero) {
            if (! preg_match('/^(.+)-(\d{4})-(\d+)$/', (string) $numero, $partes)) {
                continue;
            }

            [, $prefijo, $anio, $correlativo] = $partes;
            $clave = $prefijo.'|'.$anio;

            $maximos[$clave] = max($maximos[$clave] ?? 0, (int) $correlativo);
        }

        foreach ($maximos as $clave => $ultimo) {
            [$prefijo, $anio] = explode('|', $clave);

            DB::table('order_number_sequences')->insert([
                'prefix' => $prefijo,
                'year' => (int) $anio,
                'last_number' => $ultimo,
            ]);
        }
    }
};
