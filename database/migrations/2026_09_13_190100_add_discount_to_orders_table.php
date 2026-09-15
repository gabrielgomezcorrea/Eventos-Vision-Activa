<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // El descuento se congela con el precio: un reemplazo posterior no
        // mueve el monto. `total` pasa a ser el neto que el cliente transfiere,
        // que es contra el que contabilidad compara el comprobante.
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('subtotal')->default(0)->after('total');
            $table->unsignedBigInteger('discount_amount')->default(0)->after('subtotal');
            $table->string('discount_label')->nullable()->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['subtotal', 'discount_amount', 'discount_label']);
        });
    }
};
