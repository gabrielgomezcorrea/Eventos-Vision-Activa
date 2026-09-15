<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El evento pasa a elegir una cuenta en vez de repetir sus datos.
 *
 * Las columnas viejas se conservan por ahora: los eventos ya creados guardan
 * ahí su cuenta y hay que migrarlos antes de poder borrarlas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->foreignId('bank_account_id')->nullable()->after('city')->constrained()->nullOnDelete();
        });

        $this->migrarCuentasExistentes();
    }

    /**
     * Cada evento con datos bancarios propios se convierte en una cuenta.
     *
     * Los eventos que comparten el mismo número de cuenta terminan apuntando a
     * la misma fila, que es justamente lo que se venía a resolver.
     */
    private function migrarCuentasExistentes(): void
    {
        $eventos = DB::table('events')
            ->whereNotNull('bank_account_number')
            ->where('bank_account_number', '!=', '')
            ->get();

        $cuentasPorNumero = [];

        foreach ($eventos as $evento) {
            $numero = $evento->bank_account_number;

            if (! isset($cuentasPorNumero[$numero])) {
                $cuentasPorNumero[$numero] = DB::table('bank_accounts')->insertGetId([
                    'label' => $evento->bank_holder_name ?: 'Cuenta '.$numero,
                    'holder_name' => $evento->bank_holder_name ?: 'Sin definir',
                    'holder_rut' => $evento->bank_holder_rut,
                    'bank_name' => $evento->bank_name ?: 'Sin definir',
                    'account_type' => $evento->bank_account_type,
                    'account_number' => $numero,
                    'email' => $evento->bank_email,
                    'payment_instructions' => $evento->payment_instructions,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('events')
                ->where('id', $evento->id)
                ->update(['bank_account_id' => $cuentasPorNumero[$numero]]);
        }
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropForeign(['bank_account_id']);
            $table->dropColumn('bank_account_id');
        });
    }
};
