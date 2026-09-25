<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->string('source', 30)->default('manual')->after('status');
            $table->string('idempotency_key')->nullable()->after('source')->unique();
            $table->timestamp('paid_at')->nullable()->after('idempotency_key');
            $table->timestamp('voided_at')->nullable()->after('paid_at');
        });
        Schema::table('platform_payments', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->after('reference')->unique();
            $table->string('status', 20)->default('confirmed')->after('idempotency_key');
        });
        Schema::create('subscription_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('clinic_subscription_id')->constrained('clinic_subscriptions')->cascadeOnDelete();
            $table->foreignId('from_plan_id')->constrained('subscription_plans')->restrictOnDelete();
            $table->foreignId('to_plan_id')->constrained('subscription_plans')->restrictOnDelete();
            $table->string('billing_interval', 20);
            $table->string('timing', 20)->default('period_end');
            $table->string('status', 20)->default('scheduled');
            $table->timestamp('effective_at');
            $table->text('reason');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'effective_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_changes');
        Schema::table('platform_payments', fn (Blueprint $table) => $table->dropColumn(['idempotency_key','status']));
        Schema::table('platform_invoices', fn (Blueprint $table) => $table->dropColumn(['source','idempotency_key','paid_at','voided_at']));
    }
};
