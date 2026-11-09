<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pago a crédito: la inscripción misma sirve de orden de compra y el cliente
 * puede anotar, si quiere, el número de la suya. Texto libre: cada institución
 * numera a su manera. Los pagos existentes quedan como "bank_transfer" (contado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('purchase_order', 120)->nullable()->after('reference');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('purchase_order');
        });
    }
};
