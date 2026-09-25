<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('recipient_id')->constrained('users')->onDelete('cascade');
            $table->string('subject', 255);
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('staff_messages')->onDelete('cascade');
            $table->timestamps();

            $table->index(['recipient_id', 'read_at'], 'staff_msg_recipient_read_idx');
            $table->index('sender_id');
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_messages');
    }
};
