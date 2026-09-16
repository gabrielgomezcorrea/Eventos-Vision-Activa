<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('magic_links', function (Blueprint $table) {
            $table->id();

            // El enlace apunta a una orden. Antes de que exista la orden, el
            // enlace se emite contra el correo y crea el borrador al usarse.
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('email')->index();

            // Solo el hash se almacena. El token en claro viaja en el correo y
            // no vuelve a existir en el sistema.
            $table->string('token_hash', 64)->unique();

            // dateTime, no timestamp: MySQL le pone ON UPDATE CURRENT_TIMESTAMP a la
            // primera columna TIMESTAMP no nula y reescribiría el vencimiento.
            $table->dateTime('expires_at')->index();
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedInteger('uses')->default(0);
            $table->timestamp('revoked_at')->nullable();

            $table->string('created_ip', 45)->nullable();
            $table->timestamps();

            $table->index(['email', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('magic_links');
    }
};
