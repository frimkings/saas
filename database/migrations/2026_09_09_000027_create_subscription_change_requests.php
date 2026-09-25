<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('current_subscription_id')->nullable()->constrained('clinic_subscriptions')->nullOnDelete();
            $table->foreignId('requested_plan_id')->constrained('subscription_plans')->restrictOnDelete();
            $table->string('billing_interval', 20)->default('monthly');
            $table->string('status', 20)->default('pending');
            $table->text('message')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
            $table->index(['clinic_id','status']);
        });
    }

    public function down(): void { Schema::dropIfExists('subscription_change_requests'); }
};
