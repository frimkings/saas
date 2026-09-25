<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('code');
            $table->foreignId('supersedes_plan_id')->nullable()->after('version')
                ->constrained('subscription_plans')->nullOnDelete();
            $table->timestamp('published_at')->nullable()->after('is_active');
            $table->timestamp('retired_at')->nullable()->after('published_at');
        });

        Schema::table('clinic_subscriptions', function (Blueprint $table) {
            $table->json('plan_snapshot')->nullable()->after('subscription_plan_id');
            $table->json('pricing_snapshot')->nullable()->after('plan_snapshot');
            $table->json('feature_snapshot')->nullable()->after('pricing_snapshot');
            $table->timestamp('status_changed_at')->nullable()->after('cancelled_at');
            $table->timestamp('lifecycle_evaluated_at')->nullable()->after('status_changed_at');
            $table->timestamp('restricted_at')->nullable()->after('lifecycle_evaluated_at');
            $table->timestamp('suspended_at')->nullable()->after('restricted_at');
        });

        DB::table('subscription_plans')->whereNull('published_at')->update(['published_at' => now()]);

        DB::table('clinic_subscriptions')->orderBy('id')->chunkById(200, function ($subscriptions): void {
            $plans = DB::table('subscription_plans')
                ->whereIn('id', $subscriptions->pluck('subscription_plan_id')->unique())
                ->get()->keyBy('id');

            foreach ($subscriptions as $subscription) {
                $plan = $plans->get($subscription->subscription_plan_id);
                if (! $plan) {
                    continue;
                }

                DB::table('clinic_subscriptions')->where('id', $subscription->id)->update([
                    'plan_snapshot' => json_encode([
                        'id' => $plan->id, 'name' => $plan->name, 'code' => $plan->code,
                        'version' => $plan->version, 'included_branches' => $plan->included_branches,
                        'included_users' => $plan->included_users, 'storage_limit_mb' => $plan->storage_limit_mb,
                        'sms_allowance' => $plan->sms_allowance,
                    ]),
                    'pricing_snapshot' => json_encode([
                        'base_price' => $plan->base_price, 'annual_price' => $plan->annual_price,
                        'additional_branch_price' => $plan->additional_branch_price,
                        'currency' => $plan->currency, 'billing_interval' => $subscription->billing_interval,
                        'tax_rate' => $plan->tax_rate,
                    ]),
                    'feature_snapshot' => $plan->features ?: json_encode([]),
                    'status_changed_at' => $subscription->updated_at ?? now(),
                    'lifecycle_evaluated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('clinic_subscriptions', fn (Blueprint $table) => $table->dropColumn([
            'plan_snapshot', 'pricing_snapshot', 'feature_snapshot', 'status_changed_at',
            'lifecycle_evaluated_at', 'restricted_at', 'suspended_at',
        ]));
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supersedes_plan_id');
            $table->dropColumn(['version', 'published_at', 'retired_at']);
        });
    }
};
