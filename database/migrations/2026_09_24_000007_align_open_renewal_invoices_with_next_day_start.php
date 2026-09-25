<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renewal periods used to start on the same date the previous period ended, making every cycle
 * a day short. Unpaid renewal invoices issued that way are moved one day later so their dates
 * (and duplicate-protection key) match the corrected rule. Paid invoices are left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('platform_invoices')
            ->join('clinic_subscriptions', 'clinic_subscriptions.id', '=', 'platform_invoices.clinic_subscription_id')
            ->where('platform_invoices.source', 'renewal')
            ->where('platform_invoices.status', 'unpaid')
            ->where('platform_invoices.amount_paid', 0)
            ->whereNotNull('clinic_subscriptions.current_period_ends_at')
            ->whereRaw('DATE(platform_invoices.period_start) = DATE(clinic_subscriptions.current_period_ends_at)')
            ->whereRaw("TIME(clinic_subscriptions.current_period_ends_at) <> '00:00:00'")
            ->select('platform_invoices.id', 'platform_invoices.period_start', 'platform_invoices.period_end',
                'platform_invoices.clinic_subscription_id', 'clinic_subscriptions.billing_interval')
            ->orderBy('platform_invoices.id')
            ->get()
            ->each(function ($invoice) {
                $start = Carbon::parse($invoice->period_start)->addDay();
                $key = "subscription:{$invoice->clinic_subscription_id}:{$start->toDateString()}:{$invoice->billing_interval}";
                if (DB::table('platform_invoices')->where('idempotency_key', $key)->exists()) {
                    return; // a correctly dated invoice already exists; leave the old one for manual review
                }

                DB::table('platform_invoices')->where('id', $invoice->id)->update([
                    'period_start'    => $start->toDateString(),
                    'period_end'      => Carbon::parse($invoice->period_end)->addDay()->toDateString(),
                    'due_date'        => $start->toDateString(),
                    'idempotency_key' => $key,
                    'updated_at'      => now(),
                ]);
            });
    }

    public function down(): void
    {
        // Dates are not moved back: the shifted invoices are correct under either rule's lookup.
    }
};
