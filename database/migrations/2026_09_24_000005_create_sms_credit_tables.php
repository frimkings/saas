<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SMS becomes a prepaid product sold separately from the clinic plan: clinics buy
 * bundles of credits that never expire, and each SMS part spends one credit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_bundles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->unsignedInteger('credits');
            $table->decimal('price', 12, 2);
            $table->string('currency', 3)->default('GHS');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('sms_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->unique()->constrained('clinics')->cascadeOnDelete();
            $table->integer('balance')->default(0);
            $table->unsignedInteger('last_topup_credits')->nullable();
            $table->timestamp('low_balance_notified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sms_credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->string('type', 20); // purchase | grant | adjustment | usage | refund
            $table->integer('credits');
            $table->integer('balance_after');
            $table->unsignedBigInteger('sms_log_id')->nullable()->index(); // logs are archived, so no FK
            $table->foreignId('platform_invoice_id')->nullable()->constrained('platform_invoices')->nullOnDelete();
            $table->foreignId('sms_bundle_id')->nullable()->constrained('sms_bundles')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamps();
            $table->index(['clinic_id', 'type', 'created_at']);
        });

        foreach (['sms_logs', 'sms_logs_archive'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->unsignedSmallInteger('charged_credits')->default(0)->after('segments');
            });
        }

        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->foreignId('sms_bundle_id')->nullable()->constrained('sms_bundles')->nullOnDelete();
            $table->unsignedInteger('sms_credits')->nullable();
        });

        $this->grantOpeningBalances();
    }

    /**
     * Hosted clinics keep what is left of their plan's SMS allowance for the current
     * period as a one-off grant, so nobody loses SMS the day the plan allowance is removed.
     */
    private function grantOpeningBalances(): void
    {
        $subscriptions = DB::table('clinic_subscriptions')
            ->join('clinics', 'clinics.id', '=', 'clinic_subscriptions.clinic_id')
            ->where('clinics.deployment_mode', 'hosted')
            ->whereIn('clinic_subscriptions.status', ['active', 'trial'])
            ->orderByDesc('clinic_subscriptions.id')
            ->get(['clinic_subscriptions.clinic_id', 'clinic_subscriptions.plan_snapshot', 'clinic_subscriptions.current_period_starts_at'])
            ->unique('clinic_id');

        foreach ($subscriptions as $subscription) {
            $allowance = json_decode($subscription->plan_snapshot ?? '[]', true)['sms_allowance'] ?? null;
            if (!is_numeric($allowance)) {
                continue;
            }

            $used = (int) DB::table('sms_logs')->where('clinic_id', $subscription->clinic_id)->where('channel', 'sms')
                ->where('created_at', '>=', $subscription->current_period_starts_at ?? now()->startOfMonth())
                ->whereIn('status', ['queued', 'sent'])->sum('segments');
            $credits = max(0, (int) $allowance - $used);

            DB::table('sms_wallets')->insert(['clinic_id' => $subscription->clinic_id, 'balance' => $credits,
                'last_topup_credits' => $credits ?: null, 'created_at' => now(), 'updated_at' => now()]);

            if ($credits > 0) {
                DB::table('sms_credit_transactions')->insert(['clinic_id' => $subscription->clinic_id, 'type' => 'grant',
                    'credits' => $credits, 'balance_after' => $credits, 'note' => 'Opening balance: unused plan SMS allowance',
                    'idempotency_key' => 'opening:' . $subscription->clinic_id, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sms_bundle_id');
            $table->dropColumn('sms_credits');
        });

        foreach (['sms_logs', 'sms_logs_archive'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn('charged_credits'));
        }

        Schema::dropIfExists('sms_credit_transactions');
        Schema::dropIfExists('sms_wallets');
        Schema::dropIfExists('sms_bundles');
    }
};
