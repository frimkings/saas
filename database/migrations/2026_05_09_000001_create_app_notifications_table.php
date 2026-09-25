<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('type', 60);
            $table->string('title');
            $table->string('body');
            $table->string('icon', 80)->default('fas fa-bell');
            $table->string('icon_color', 40)->default('text-primary');
            $table->string('action_url')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at'], 'app_notifs_user_read_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
