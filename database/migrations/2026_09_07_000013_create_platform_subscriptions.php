<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_admin')->default(false)->after('is_active')->index();
        });

        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->unsignedInteger('included_branches')->nullable();
            $table->decimal('base_price', 12, 2)->default(0);
            $table->decimal('additional_branch_price', 12, 2)->default(0);
            $table->string('billing_interval', 20)->default('monthly');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('clinic_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('subscription_plan_id')->constrained('subscription_plans')->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('branch_limit_override')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_starts_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['clinic_id', 'status']);
        });

        $planId = DB::table('subscription_plans')->insertGetId([
            'name' => 'Legacy Unlimited', 'code' => 'legacy-unlimited',
            'included_branches' => null, 'base_price' => 0, 'additional_branch_price' => 0,
            'billing_interval' => 'monthly', 'is_active' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('clinics')->select('id')->orderBy('id')->chunkById(500, function ($clinics) use ($planId) {
            foreach ($clinics as $clinic) {
                DB::table('clinic_subscriptions')->insert([
                    'clinic_id' => $clinic->id, 'subscription_plan_id' => $planId,
                    'status' => 'active', 'current_period_starts_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_subscriptions');
        Schema::dropIfExists('subscription_plans');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_platform_admin'));
    }
};
