<?php

namespace App\Services\Finance;

use App\Models\AuditTrail;
use App\Models\CashMovement;
use App\Models\DayClose;
use App\Models\PaymentCorrection;
use App\Models\PaymentTransaction;
use App\Models\RefundLog;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Closing a branch's day at the till. The cashier counts what is there, the difference from
 * what the cash book expects is recorded, and what is banked or handed over leaves the drawer.
 * A pending void or payment correction for the day has to be decided first. Once closed, the
 * day's voids, payment corrections and cash movements are settled; the owner can reopen it.
 */
class DayCloseService
{
    public function __construct(private CashBook $book) {}

    public static function isOwner(): bool
    {
        return (bool) auth()->user()?->hasRole('Super Admin');
    }

    public function find(CarbonInterface|string $date): ?DayClose
    {
        return DayClose::whereDate('business_date', Carbon::parse($date)->toDateString())->first();
    }

    public function isClosed(CarbonInterface|string|null $date): bool
    {
        return $date !== null && (bool) $this->find($date)?->isClosed();
    }

    /** Refuse a change to a day that has been closed. */
    public function assertOpen(CarbonInterface|string|null $date, string $what): void
    {
        if ($this->isClosed($date)) {
            throw new RuntimeException('The day (' . Carbon::parse($date)->format('d M Y') . ") has been closed, so {$what} can no longer be changed. Ask the owner to reopen it.");
        }
    }

    /** Requests that must be decided before the day can close. */
    public function blockers(CarbonInterface $date): array
    {
        $range = [$date->copy()->startOfDay(), $date->copy()->endOfDay()];

        return [
            'voids' => RefundLog::with('sale:id,transaction_id,created_at')
                ->where('request_type', RefundLog::TYPE_VOID)
                ->whereIn('status', [RefundLog::STATUS_PENDING, RefundLog::STATUS_APPROVED])
                ->whereHas('sale', fn ($q) => $q->whereBetween('created_at', $range))->get(),
            'corrections' => PaymentCorrection::with('payment:id,created_at')
                ->where('status', PaymentCorrection::PENDING)
                ->whereHas('payment', fn ($q) => $q->whereBetween('created_at', $range))->get(),
        ];
    }

    /**
     * @param  array<string, float|string|null>  $counted  method => amount counted; blank = not checked (cash must be counted)
     */
    public function close(CarbonInterface $date, array $counted, float $banked = 0, ?string $notes = null): DayClose
    {
        $date = $date->copy()->startOfDay();
        if ($date->gt(today())) throw new RuntimeException('A day can only be closed once it has started.');

        return DB::transaction(function () use ($date, $counted, $banked, $notes) {
            $existing = DayClose::whereDate('business_date', $date->toDateString())->lockForUpdate()->first();
            if ($existing?->isClosed()) throw new RuntimeException('This day is already closed.');

            $blockers = $this->blockers($date);
            if ($blockers['voids']->isNotEmpty() || $blockers['corrections']->isNotEmpty()) {
                throw new RuntimeException('Decide the pending voids and payment corrections for this day before closing it.');
            }

            $figures = $this->book->day($date);
            $counted = collect($counted)->map(fn ($value) => $value === null || $value === '' ? null : round((float) $value, 2));
            if (isset($figures['cash']) && $counted->get('cash') === null) {
                throw new RuntimeException('Count the cash in the drawer before closing the day.');
            }
            if ($counted->filter(fn ($value) => $value !== null && $value < 0)->isNotEmpty()) {
                throw new RuntimeException('A count cannot be below zero.');
            }
            $banked = round(max(0, $banked), 2);
            if ($banked > (float) $counted->get('cash', 0) + 0.005) {
                throw new RuntimeException('You cannot bank more cash than was counted.');
            }

            $methods = [];
            $variance = 0.0;
            foreach ($figures as $key => $figure) {
                $count = $counted->get($key);
                $difference = $count === null ? null : round($count - $figure['expected'], 2);
                $methods[$key] = $figure + ['counted' => $count, 'variance' => $difference];
                $variance += $difference ?? 0;
            }

            $close = $existing ?? new DayClose(['business_date' => $date->toDateString()]);
            $close->fill([
                'status' => DayClose::CLOSED, 'methods' => $methods, 'cashiers' => $this->book->cashiers($date),
                'variance' => round($variance, 2), 'banked' => $banked, 'notes' => $notes ?: null,
                'closed_by' => auth()->id(), 'closed_at' => now(),
            ])->save();

            foreach ($methods as $key => $method) {
                if ($method['variance'] !== null && abs($method['variance']) >= 0.005) {
                    $this->movement($close, CashMovement::VARIANCE, $key, $method['variance'], $method['variance'] > 0 ? 'Over at day close' : 'Short at day close');
                }
            }
            if ($banked > 0) {
                $this->movement($close, CashMovement::BANKED, 'cash', -$banked, 'At day close');
            }

            AuditTrail::record('day.closed', 'Closed the day ' . $date->format('d M Y') . ' (difference ' . number_format($variance, 2) . ', banked ' . number_format($banked, 2) . ')', $close, [], ['methods' => $methods]);

            return $close;
        });
    }

    public function reopen(DayClose $close, string $reason): void
    {
        if (!self::isOwner()) throw new RuntimeException('Only the owner can reopen a closed day.');
        if (!$close->isClosed()) throw new RuntimeException('This day is not closed.');
        if (trim($reason) === '') throw new RuntimeException('Give a reason for reopening the day.');

        DB::transaction(function () use ($close, $reason) {
            CashMovement::active()->where('day_close_id', $close->id)->get()->each(fn (CashMovement $movement) => $movement->update([
                'cancelled_at' => now(), 'cancelled_by' => auth()->id(), 'cancel_reason' => 'Day reopened: ' . $reason,
            ]));
            $close->update(['status' => DayClose::REOPENED, 'reopened_by' => auth()->id(), 'reopened_at' => now(), 'reopen_reason' => $reason]);
            AuditTrail::record('day.reopened', 'Reopened the day ' . $close->business_date->format('d M Y') . ': ' . $reason, $close);
        });
    }

    /** Money moved by hand on the cash book (banked, added, opening count). */
    public function recordMovement(string $type, string $method, float $amount, CarbonInterface $date, ?string $reference = null, ?string $notes = null): CashMovement
    {
        if (!in_array($type, [CashMovement::OPENING, CashMovement::BANKED, CashMovement::ADDED], true)) throw new RuntimeException('Choose what kind of movement this is.');
        if ($amount <= 0 && $type !== CashMovement::OPENING) throw new RuntimeException('Enter an amount above zero.');
        if ($amount < 0) throw new RuntimeException('An opening balance cannot be below zero.');
        if ($date->gt(today())) throw new RuntimeException('Money cannot be moved on a future date.');
        $this->assertOpen($date, 'its cash book');

        $movement = CashMovement::create([
            'type' => $type, 'payment_method' => $method, 'amount' => $type === CashMovement::BANKED ? -$amount : $amount,
            'moved_on' => $date->toDateString(), 'reference' => $reference ?: null, 'notes' => $notes ?: null, 'recorded_by' => auth()->id(),
        ]);
        AuditTrail::record('cash_movement.created', CashMovement::LABELS[$type] . ' of ' . number_format($amount, 2) . " ({$method})", $movement);

        return $movement;
    }

    public function cancelMovement(CashMovement $movement, string $reason): void
    {
        if ($movement->cancelled_at) throw new RuntimeException('This entry is already cancelled.');
        if ($movement->day_close_id) throw new RuntimeException('This entry was made by a day close; reopen the day instead.');
        if (trim($reason) === '') throw new RuntimeException('Give a reason for cancelling the entry.');
        $this->assertOpen($movement->moved_on, 'its cash book');

        $movement->update(['cancelled_at' => now(), 'cancelled_by' => auth()->id(), 'cancel_reason' => $reason]);
        AuditTrail::record('cash_movement.cancelled', 'Cancelled ' . strtolower($movement->label) . ' of ' . number_format(abs((float) $movement->amount), 2) . ': ' . $reason, $movement);
    }

    /** Payments taken on a day, newest first, for the close screen. */
    public function payments(CarbonInterface $date): Collection
    {
        return PaymentTransaction::with(['sale' => fn ($q) => $q->withTrashed()->select('id', 'transaction_id', 'patient_id', 'customer_name', 'business_line'),
            'sale.patient:id,name', 'collectedBy:id,name'])
            ->whereBetween('created_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->latest('id')->get();
    }

    private function movement(DayClose $close, string $type, string $method, float $amount, string $notes): void
    {
        CashMovement::create([
            'type' => $type, 'payment_method' => $method, 'amount' => $amount, 'moved_on' => $close->business_date->toDateString(),
            'notes' => $notes, 'recorded_by' => auth()->id(), 'day_close_id' => $close->id,
        ]);
    }
}
