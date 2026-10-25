<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bills an insurer paid less on (or rejected) kept the original insurer share on their
 * lines, which blocked refunds. Bring the lines down to the bill's insurer share, in
 * proportion. Bills with returned items are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $saleIds = DB::table('insurance_claims')->whereNull('deleted_at')->where('shortfall_amount', '>', 0)
            ->whereNotNull('sale_id')->pluck('sale_id')->unique();

        foreach ($saleIds as $saleId) {
            $saleShare = (float) DB::table('sales')->where('id', $saleId)->value('insurer_amount');
            $items = DB::table('sale_items')->where('sale_id', $saleId)->whereNull('deleted_at')->orderBy('id')
                ->get(['id', 'insurer_amount', 'refunded_quantity']);
            if ($items->contains(fn ($item) => (int) $item->refunded_quantity > 0)) {
                continue;
            }

            $lineTotal = (float) $items->sum('insurer_amount');
            if ($lineTotal - $saleShare <= 0.005) {
                continue;
            }

            $left = round($saleShare, 2);
            $shared = $items->filter(fn ($item) => (float) $item->insurer_amount > 0)->values();
            foreach ($shared as $i => $item) {
                $share = $i === $shared->count() - 1
                    ? $left
                    : min($left, round($saleShare * (float) $item->insurer_amount / $lineTotal, 2));
                DB::table('sale_items')->where('id', $item->id)->update(['insurer_amount' => max(0, $share)]);
                $left = round($left - $share, 2);
            }
        }
    }

    public function down(): void
    {
        // The original shares are in the audit trail; nothing to undo here.
    }
};
