<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The early "BASIC" plans sold to optical shops (every version) are named "Standard Optical" (18 Nov 2026),
 * so they no longer read as the most basic clinic plan. Only the name changes, in place: prices, limits and
 * features stay, and clinics stay on the same plan row. A BASIC plan that also includes the clinic is left as it is.
 */
return new class extends Migration
{
    private const OLD = 'BASIC';
    private const NEW = 'Standard Optical';

    public function up(): void
    {
        $this->rename(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->rename(self::NEW, self::OLD);
    }

    private function rename(string $from, string $to): void
    {
        $ids = DB::table('subscription_plans')->where('name', $from)->where('product', 'optical')->pluck('id');
        if ($ids->isEmpty()) return;

        DB::table('subscription_plans')->whereIn('id', $ids)->update(['name' => $to, 'updated_at' => now()]);

        // The copy each subscription keeps of its plan (shown in analytics) carries the name too.
        DB::table('clinic_subscriptions')->whereIn('subscription_plan_id', $ids)->whereNotNull('plan_snapshot')->orderBy('id')
            ->each(function ($row) use ($from, $to) {
                $snapshot = json_decode($row->plan_snapshot, true);
                if (! is_array($snapshot) || ($snapshot['name'] ?? null) !== $from) return;
                $snapshot['name'] = $to;
                DB::table('clinic_subscriptions')->where('id', $row->id)->update(['plan_snapshot' => json_encode($snapshot)]);
            });
    }
};
