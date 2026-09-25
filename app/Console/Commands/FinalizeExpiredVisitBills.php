<?php

namespace App\Console\Commands;

use App\Models\AuditTrail;
use App\Models\Sales;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FinalizeExpiredVisitBills extends Command
{
    protected $signature = 'visit-bills:finalize-expired';
    protected $description = 'Finalize open visit bills after their secure append window expires';

    public function handle(): int
    {
        Sales::query()
            ->where('bill_status', 'open')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->select('id')
            ->chunkById(100, function ($sales): void {
                foreach ($sales as $candidate) {
                    DB::transaction(function () use ($candidate): void {
                        $sale = Sales::lockForUpdate()->find($candidate->id);
                        if (!$sale || !$sale->isOpenVisitBill() || !$sale->expires_at?->isPast()) {
                            return;
                        }

                        $old = $sale->only(['bill_status', 'bill_version', 'expires_at']);
                        $sale->update([
                            'bill_status' => 'finalized',
                            'finalized_at' => now(),
                            'bill_version' => $sale->bill_version + 1,
                        ]);
                        AuditTrail::record('visit_bill.expired', "Visit bill {$sale->transaction_id} finalized after expiry", $sale, $old, $sale->only(['bill_status', 'bill_version', 'finalized_at']), $sale->patient_id, true);
                    });
                }
            });

        return self::SUCCESS;
    }
}
