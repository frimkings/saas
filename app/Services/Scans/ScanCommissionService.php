<?php

namespace App\Services\Scans;

use App\Models\AuditTrail;
use App\Models\DiagnosticScan;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\SaleItem;
use App\Models\Sales;
use App\Models\ScanCommission;
use App\Models\ScanCommissionPayout;
use App\Models\ScanOrder;
use App\Models\ScanOrderItem;
use App\Models\ScanPartnerClinic;
use App\Models\ScanPartnerRate;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Referrers' commission on scans. One rule keeps it right however the money moves: whenever a scan
 * sale's payment or refunds change, work out what each scan should have earned (nothing until the
 * bill is fully paid; nothing on what was refunded) and correct the difference. Unpaid rows are
 * voided and rewritten; paid rows are never touched, a negative correction is added instead and
 * the next payout nets it off. Rates are frozen on a scan's first row.
 */
class ScanCommissionService
{
    public const EXPENSE_CATEGORY = 'Referral commissions';

    /** Bring the commissions of the scan order billed on this sale in line with the sale. */
    public function syncForSale(Sales $sale): void
    {
        $order = ScanOrder::withoutGlobalScope('branch')->where('sale_id', $sale->id)->first();
        if (! $order || $order->source === 'self') return;

        DB::transaction(function () use ($order) {
            $order = ScanOrder::withoutGlobalScope('branch')->with('items.scan')->lockForUpdate()->findOrFail($order->id);
            $sale = Sales::withoutGlobalScope('branch')->findOrFail($order->sale_id);
            $earning = $sale->payment_status === 'paid' && ! $sale->is_refunded;
            $saleItems = SaleItem::withoutGlobalScope('branch')->whereIn('id', $order->items->pluck('sale_item_id')->filter())->get()->keyBy('id');

            foreach ($order->items as $item) {
                $saleItem = $saleItems[$item->sale_item_id] ?? null;
                $quantity = $saleItem ? max(0, (int) $saleItem->dispensed_quantity - (int) $saleItem->refunded_quantity) : 0;
                $this->syncItem($order, $item, $earning ? $quantity : 0);
            }
        });
    }

    private function syncItem(ScanOrder $order, ScanOrderItem $item, int $quantity): void
    {
        $payee = $this->payee($order);
        if (! $payee) return;

        $rows = ScanCommission::where('scan_order_item_id', $item->id)->orderBy('id')->get();
        // The rate agreed when the scan first earned keeps applying to it.
        [$rateType, $rateValue] = $rows->isNotEmpty()
            ? [$rows->first()->rate_type, (float) $rows->first()->rate_value]
            : $this->rateFor($order, $item->scan);

        $base = round((float) $item->unit_price * $quantity, 2);
        $due = round(match ($rateType) {
            'percent' => $base * $rateValue / 100,
            'fixed' => $rateValue * $quantity,
            default => 0.0,
        }, 2);

        $live = $rows->where('status', '!=', 'void');
        if (abs($due - (float) $live->sum('amount')) < 0.005) return;

        foreach ($live->where('status', 'accrued') as $row) {
            $row->forceFill(['status' => 'void', 'voided_at' => now(), 'void_reason' => $quantity === 0 ? 'Scan refunded or bill no longer paid' : 'Recalculated'])->save();
        }
        $paid = (float) $live->where('status', 'paid')->sum('amount');
        $difference = round($due - $paid, 2);
        if (abs($difference) < 0.005) return;

        $row = ScanCommission::create([
            'branch_id' => $order->branch_id, 'scan_order_id' => $order->id, 'scan_order_item_id' => $item->id,
            'payee_type' => $payee[0], 'scan_partner_clinic_id' => $payee[0] === 'partner' ? $payee[1] : null, 'user_id' => $payee[0] === 'staff' ? $payee[1] : null,
            'base_amount' => $base, 'rate_type' => $rateType, 'rate_value' => $rateValue, 'amount' => $difference,
            'kind' => $paid > 0 ? 'correction' : 'earned', 'earned_on' => now()->toDateString(), 'status' => 'accrued',
        ]);
        if ($row->kind === 'correction') {
            AuditTrail::record('scans.commission_correction', "Commission correction of ".currency().' '.number_format($difference, 2)." on {$order->order_number} ({$item->label()}): commission already paid",
                $row, ['paid' => $paid], ['due' => $due], $order->patient_id, true);
        }
    }

    /** ['partner', id], ['staff', userId], or null for self-referred. */
    private function payee(ScanOrder $order): ?array
    {
        return match ($order->source) {
            'partner' => $order->scan_partner_clinic_id ? ['partner', (int) $order->scan_partner_clinic_id] : null,
            'internal' => $order->ordered_by ? ['staff', (int) $order->ordered_by] : null,
            default => null,
        };
    }

    /** A partner's agreed rate for the scan, else the catalogue's. Our doctors earn the catalogue rate. */
    public function rateFor(ScanOrder $order, ?DiagnosticScan $scan): array
    {
        if (! $scan) return ['none', 0.0];
        if ($order->source === 'partner') {
            $agreed = ScanPartnerRate::where('scan_partner_clinic_id', $order->scan_partner_clinic_id)->where('diagnostic_scan_id', $scan->id)->first();
            if ($agreed) return [$agreed->commission_type, (float) $agreed->commission_value];
        }

        return [$scan->commission_type, (float) $scan->commission_value];
    }

    // --- Balances and payouts ----------------------------------------------------------------

    /** Unpaid commission per payee: list of ['type', 'id', 'name', 'amount', 'count', 'oldest']. */
    public function owed(): Collection
    {
        $rows = ScanCommission::where('status', 'accrued')
            ->selectRaw('payee_type, scan_partner_clinic_id, user_id, SUM(amount) as amount, COUNT(*) as count, MIN(earned_on) as oldest')
            ->groupBy('payee_type', 'scan_partner_clinic_id', 'user_id')->get();
        $partners = ScanPartnerClinic::whereIn('id', $rows->pluck('scan_partner_clinic_id')->filter())->get()->keyBy('id');
        $users = User::whereIn('id', $rows->pluck('user_id')->filter())->get(['id', 'name'])->keyBy('id');

        return $rows->map(fn ($row) => [
            'type' => $row->payee_type,
            'id' => (int) ($row->payee_type === 'partner' ? $row->scan_partner_clinic_id : $row->user_id),
            'name' => $row->payee_type === 'partner' ? ($partners[$row->scan_partner_clinic_id]->name ?? 'Partner clinic') : ($users[$row->user_id]->name ?? 'Doctor'),
            'code' => $row->payee_type === 'partner' ? ($partners[$row->scan_partner_clinic_id]->code ?? null) : null,
            'amount' => round((float) $row->amount, 2), 'count' => (int) $row->count, 'oldest' => $row->oldest,
        ])->sortByDesc('amount')->values();
    }

    /** Pay a partner or doctor everything they earned up to a day (corrections included), by voucher. */
    public function payOut(string $type, int $id, CarbonInterface $upTo, string $method, ?string $reference, ?string $notes): ScanCommissionPayout
    {
        if (! array_key_exists($method, Expense::PAYMENT_METHODS)) {
            throw ValidationException::withMessages(['payMethod' => 'Choose how the commission is paid.']);
        }
        $name = $type === 'partner' ? ScanPartnerClinic::findOrFail($id)->name : User::findOrFail($id)->name;

        return DB::transaction(function () use ($type, $id, $upTo, $method, $reference, $notes, $name) {
            $rows = ScanCommission::for($type, $id)->where('status', 'accrued')->whereDate('earned_on', '<=', $upTo->toDateString())->lockForUpdate()->get();
            $amount = round((float) $rows->sum('amount'), 2);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['payAmount' => $rows->isEmpty()
                    ? 'Nothing is owed up to that day.'
                    : 'Corrections for refunded scans cancel out what is owed, so there is nothing to pay.']);
            }

            $voucher = ScanCommissionPayout::nextNumber();
            $category = ExpenseCategory::firstOrCreate(['name' => self::EXPENSE_CATEGORY], ['section' => ExpenseCategory::OPERATING, 'is_active' => true,
                'description' => 'Commission paid to partner clinics and doctors for referred diagnostic scans.']);
            $expense = Expense::create([
                'expense_category_id' => $category->id, 'expense_date' => now()->toDateString(),
                'description' => "Scan referral commission {$voucher}", 'payee' => $name, 'amount' => $amount, 'payment_method' => $method,
                'reference' => $reference ?: $voucher, 'notes' => $notes, 'recorded_by' => Auth::id(), 'business_line' => Expense::CLINIC,
            ]);
            $payout = ScanCommissionPayout::create([
                'voucher_number' => $voucher, 'payee_type' => $type, 'scan_partner_clinic_id' => $type === 'partner' ? $id : null, 'user_id' => $type === 'staff' ? $id : null,
                'route' => 'voucher', 'period_to' => $upTo->toDateString(), 'amount' => $amount, 'payment_method' => $method,
                'reference' => $reference ?: null, 'notes' => $notes ?: null, 'expense_id' => $expense->id, 'paid_by' => Auth::id(), 'paid_at' => now(),
            ]);
            ScanCommission::whereIn('id', $rows->pluck('id'))->update(['status' => 'paid', 'scan_commission_payout_id' => $payout->id, 'updated_at' => now()]);

            AuditTrail::record('scans.commission_payout', "Paid {$name} ".currency().' '.number_format($amount, 2)." scan commission ({$voucher}, {$rows->count()} lines, up to ".$upTo->format('d M Y').')',
                $payout, [], ['amount' => $amount, 'method' => $method, 'lines' => $rows->count()], null, true);

            return $payout;
        });
    }
}
