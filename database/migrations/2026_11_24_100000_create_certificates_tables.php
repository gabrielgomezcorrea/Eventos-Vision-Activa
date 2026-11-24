<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Certificates made outside the system (24/11/2026): uploaded as PDF, matched
 * to a participant and sent one by one, spaced in time, never all at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status')->default('sending');
            // dateTime, not timestamp: MySQL rewrites the first timestamp of a table on every UPDATE.
            $table->dateTime('next_send_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->string('paused_reason')->nullable();
            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('participant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->char('file_hash', 64);
            $table->text('pdf_text')->nullable();
            $table->string('detected_name')->nullable();
            $table->unsignedTinyInteger('match_score')->nullable();
            $table->string('status')->default('review');
            $table->dateTime('queued_at')->nullable();
            $table->dateTime('sending_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->string('sent_to')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The same file uploaded twice is skipped, not duplicated.
            $table->unique(['event_id', 'file_hash']);
            $table->index(['event_id', 'status']);
            $table->index(['status', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
        Schema::dropIfExists('certificate_campaigns');
    }
};
