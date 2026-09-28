<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every email sent to a clinic owner (approvals and sales summaries), one row per message.
 * The key stops the same message going twice; failed ones are retried from here.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('owner_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('dedupe_key')->unique();
            $table->string('recipient')->nullable();
            $table->string('subject');
            $table->string('status', 20);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['clinic_id', 'kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_emails');
    }
};
