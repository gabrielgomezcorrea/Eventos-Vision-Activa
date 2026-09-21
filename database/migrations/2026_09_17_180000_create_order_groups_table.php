<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `order_groups` se crea antes de agregar las claves foráneas que la
     * referencian: MySQL falla con 1215 si la tabla referida no existe todavía.
     */
    public function up(): void
    {
        Schema::create('order_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('responsible_email')->index();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->after('event_id')
                ->constrained('order_groups')->nullOnDelete();
        });

        Schema::table('magic_links', function (Blueprint $table) {
            $table->foreignId('order_group_id')->nullable()->after('order_id')
                ->constrained('order_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('magic_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_group_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('group_id');
        });

        Schema::dropIfExists('order_groups');
    }
};
