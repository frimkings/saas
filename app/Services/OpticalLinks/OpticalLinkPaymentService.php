<?php

namespace App\Services\OpticalLinks;

use App\Models\AuditTrail;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\LensOrder;
use App\Models\OpticalLinkPayment;
use App\Models\OpticalOrderRequest;
use App\Models\OpticalPartnerClinic;
use App\Models\OpticalPartnerPayment;
use App\Models\OpticalPartnerPaymentAllocation;
use App\Models\User;
use App\Services\ClinicAccessService;
use App\Services\Finance\DayCloseService;
use App\Services\Finance\PeriodLockService;
use App\Services\OpticalPartnerAccountService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Money between a linked clinic and its optical centre.
 *
 * The clinic records what it paid: that is its own expense at once ("Optical centre jobs"), and a
 * shared payment row the optical centre confirms. Confirming books the money on the optical
 * centre's partner account (OpticalPartnerAccountService::recordPayment), which settles the jobs;
 * the clinic's view follows through the jobs it mirrors. Neither side writes the other's tables.
 */
class OpticalLinkPaymentService
{
    /* ---------------------------------------------------------------- what the clinic owes (clinic side) */

    /** Jobs this clinic sent that are on an optical centre's account (accepted, not cancelled). */
    public function billedJobs(int $partnerId): Collection
    {
        return OpticalOrderRequest::forSender($this->clinicId())->where('optical_partner_clinic_id', $partnerId)
            ->where('status', 'accepted')->whereNull('order_cancelled_at')->whereNotNull('order_total')
            ->orderBy('created_at')->orderBy('id')->get();
    }

    public function openJobs(int $partnerId): Collection
    {
        return $this->billedJobs($partnerId)->filter(fn (OpticalOrderRequest $job) => $job->balance() > 0)->values();
    }

    /** Money the clinic says it paid that the optical centre has not confirmed yet, per job. */
    public function waitingByJob(int $partnerId): array
    {
        $waiting = [];
        OpticalLinkPayment::forSender($this->clinicId())->where('optical_partner_clinic_id', $partnerId)
            ->whereIn('status', OpticalLinkPayment::WAITING)->get()
            ->each(function (OpticalLinkPayment $payment) use (&$waiting) {
                foreach ((array) $payment->allocations as $requestId => $amount) {
                    $waiting[(int) $requestId] = round(($waiting[(int) $requestId] ?? 0) + (float) $amount, 2);
                }
            });

        return $waiting;
    }

    /**
     * One card per linked optical centre: billed, paid, waiting for confirmation, still to pay, aging.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function summary(): Collection
    {
        $links = OpticalPartnerClinic::withoutGlobalScopes()->with('clinic')->where('linked_clinic_id', $this->clinicId())->orderBy('id')->get();

        return $links->map(function (OpticalPartnerClinic $link) {
            $jobs = $this->billedJobs($link->id);
            $waiting = $this->waitingByJob($link->id);
            $buckets = array_fill_keys(array_keys(OpticalPartnerAccountService::AGING), 0.0);
            foreach ($jobs as $job) {
                $left = round(max(0, $job->balance() - ($waiting[$job->id] ?? 0)), 2);
                if ($left <= 0) continue;
                $days = (int) $job->created_at->copy()->startOfDay()->diffInDays(now()->startOfDay());
                $key = $days <= 30 ? '0_30' : ($days <= 60 ? '31_60' : ($days <= 90 ? '61_90' : '90_plus'));
                $buckets[$key] = round($buckets[$key] + $left, 2);
            }
            $billed = round($jobs->sum(fn ($job) => (float) $job->order_total), 2);
            $paid = round($jobs->sum(fn ($job) => (float) $job->order_paid), 2);
            $pending = round(array_sum($waiting), 2);

            return [
                'link' => $link, 'billed' => $billed, 'paid' => $paid, 'waiting' => $pending,
                'to_pay' => round(max(0, $billed - $paid - $pending), 2), 'aging' => $buckets,
                'open' => $jobs->filter(fn ($job) => $job->balance() > 0)->values(),
            ];
        })->filter(fn ($row) => $row['billed'] > 0 || in_array($row['link']->link_status, ['active', 'paused'], true))->values();
    }

    /* ---------------------------------------------------------------- recording (clinic side) */

    /**
     * The clinic paid the optical centre: book its expense and send the payment for confirmation.
     *
     * @param  array<int, float|string|null>|null  $allocations  request id => amount (null: oldest first)
     */
    public function record(int $partnerId, float $amount, string $method, ?string $reference, ?string $notes, ?array $allocations, User $by): OpticalLinkPayment
    {
        $this->assertManager($by);
        app(ClinicAccessService::class)->assertWritable();
        $clinicId = $this->clinicId();
        $amount = round($amount, 2);
        if (! array_key_exists($method, OpticalPartnerPayment::METHODS)) throw ValidationException::withMessages(['payMethod' => 'Choose how you paid.']);
        if ($amount <= 0) throw ValidationException::withMessages(['payAmount' => 'Enter the amount you paid.']);
        if ($this->dayClosed()) throw ValidationException::withMessages(['payAmount' => 'Today has been closed, so no new expenses can be recorded. Ask the owner to reopen it.']);

        return DB::transaction(function () use ($clinicId, $partnerId, $amount, $method, $reference, $notes, $allocations, $by) {
            $link = OpticalPartnerClinic::withoutGlobalScopes()->with('clinic')->where('linked_clinic_id', $clinicId)->lockForUpdate()->findOrFail($partnerId);
            $waiting = $this->waitingByJob($link->id);
            $open = $this->openJobs($link->id)->keyBy('id')
                ->map(fn (OpticalOrderRequest $job) => round(max(0, $job->balance() - ($waiting[$job->id] ?? 0)), 2))
                ->filter(fn ($left) => $left > 0);
            $owed = round($open->sum(), 2);
            if ($amount > $owed) {
                throw ValidationException::withMessages(['payAmount' => 'This is more than the '.currency().' '.number_format($owed, 2).' you still owe '.$link->clinic?->name.' (payments waiting for confirmation are already counted).']);
            }
            $plan = $allocations === null ? $this->oldestFirst($open, $amount) : $this->manualPlan($open, $amount, $allocations);
            $voucher = OpticalLinkPayment::nextVoucher();
            $expense = $this->bookExpense($link, $amount, $method, $reference ?: $voucher, "Optical centre jobs {$voucher}", $notes);
            $payment = OpticalLinkPayment::create([
                'sender_clinic_id' => $clinicId, 'optical_clinic_id' => $link->clinic_id, 'optical_partner_clinic_id' => $link->id,
                'voucher_number' => $voucher, 'amount' => $amount, 'payment_method' => $method,
                'reference' => $this->clean($reference), 'notes' => $this->clean($notes), 'allocations' => $plan,
                'status' => 'pending', 'expense_id' => $expense->id, 'recorded_by' => $by->id,
            ]);
            AuditTrail::record('optical.link_payment_recorded', 'Paid '.$link->clinic?->name.' '.currency().' '.number_format($amount, 2).' ('.$voucher.'), waiting for them to confirm',
                $expense, [], ['payment_id' => $payment->id, 'amount' => $amount, 'method' => $method, 'jobs' => count($plan)], null, true);

            return $payment;
        });
    }

    /** Pending or disputed: the clinic takes it back and its expense is removed. */
    public function withdraw(OpticalLinkPayment $payment, User $by): void
    {
        $this->assertManager($by);
        app(ClinicAccessService::class)->assertWritable();
        DB::transaction(function () use ($payment, $by) {
            $payment = OpticalLinkPayment::forSender($this->clinicId())->lockForUpdate()->findOrFail($payment->id);
            abort_unless(in_array($payment->status, OpticalLinkPayment::WAITING, true), 422, 'Only a payment the optical centre has not confirmed can be withdrawn.');
            $expense = $payment->expense_id ? Expense::find($payment->expense_id) : null;
            if ($expense) {
                $date = $expense->expense_date->toDateString();
                if (app(DayCloseService::class)->isClosed($date) || app(PeriodLockService::class)->covering(Expense::CLINIC, $date)) {
                    throw ValidationException::withMessages(['payment' => 'The expense for this payment is in a closed day or locked period. Ask the owner to correct it in Expenses.']);
                }
                AuditTrail::record('expense.deleted', "Deleted expense: {$expense->description} (".currency()." {$expense->amount}) — optical payment withdrawn", $expense, $expense->toArray(), []);
                $expense->delete();
            }
            $payment->forceFill(['status' => 'withdrawn'])->save();
            AuditTrail::record('optical.link_payment_withdrawn', 'Withdrew payment '.$payment->voucher_number.' of '.currency().' '.number_format((float) $payment->amount, 2), null,
                [], ['payment_id' => $payment->id], null, true);
        });
    }

    /**
     * Payments the optical centre booked itself (cash handed over at the counter, say) that are
     * not in the clinic's expenses yet.
     *
     * @return Collection<int, array{payment: OpticalPartnerPayment, amount: float, jobs: Collection, allocations: array<int, float>}>
     */
    public function unbookedReceipts(int $partnerId): Collection
    {
        $jobs = $this->billedJobs($partnerId)->keyBy('lens_order_id');
        if ($jobs->isEmpty()) return collect();
        $booked = OpticalLinkPayment::where('optical_partner_clinic_id', $partnerId)->whereNotNull('optical_partner_payment_id')->pluck('optical_partner_payment_id');
        $allocations = OpticalPartnerPaymentAllocation::withoutGlobalScopes()->whereIn('lens_order_id', $jobs->keys())
            ->whereNotIn('optical_partner_payment_id', $booked)->get()->groupBy('optical_partner_payment_id');
        $payments = OpticalPartnerPayment::withoutGlobalScopes()->whereIn('id', $allocations->keys())
            ->where('optical_partner_clinic_id', $partnerId)->orderBy('created_at')->get();

        return $payments->map(fn (OpticalPartnerPayment $payment) => [
            'payment' => $payment,
            'amount' => round((float) $allocations->get($payment->id)->sum('amount'), 2),
            'jobs' => $allocations->get($payment->id)->map(fn ($allocation) => $jobs->get($allocation->lens_order_id))->filter()->values(),
            'allocations' => $allocations->get($payment->id)->filter(fn ($allocation) => $jobs->has($allocation->lens_order_id))
                ->mapWithKeys(fn ($allocation) => [$jobs->get($allocation->lens_order_id)->id => round((float) $allocation->amount, 2)])->all(),
        ])->values();
    }

    /** Book a receipt the optical centre recorded as the clinic's own expense (once). */
    public function bookReceipt(int $partnerId, int $receiptId, User $by): OpticalLinkPayment
    {
        $this->assertManager($by);
        app(ClinicAccessService::class)->assertWritable();
        if ($this->dayClosed()) throw ValidationException::withMessages(['payment' => 'Today has been closed, so no new expenses can be recorded. Ask the owner to reopen it.']);

        return DB::transaction(function () use ($partnerId, $receiptId, $by) {
            $link = OpticalPartnerClinic::withoutGlobalScopes()->with('clinic')->where('linked_clinic_id', $this->clinicId())->lockForUpdate()->findOrFail($partnerId);
            $row = $this->unbookedReceipts($link->id)->first(fn ($row) => (int) $row['payment']->id === $receiptId);
            if (! $row) throw ValidationException::withMessages(['payment' => 'This payment is already in your expenses.']);
            $receipt = $row['payment'];
            $expense = $this->bookExpense($link, $row['amount'], $receipt->payment_method, $receipt->receipt_number, 'Optical centre jobs, receipt '.$receipt->receipt_number, $receipt->notes, $receipt->created_at->toDateString());
            $payment = OpticalLinkPayment::create([
                'sender_clinic_id' => $this->clinicId(), 'optical_clinic_id' => $link->clinic_id, 'optical_partner_clinic_id' => $link->id,
                'voucher_number' => OpticalLinkPayment::nextVoucher(), 'amount' => $row['amount'], 'payment_method' => $receipt->payment_method,
                'reference' => $receipt->receipt_number, 'notes' => 'Recorded by the optical centre',
                'allocations' => $row['allocations'],
                'status' => 'confirmed', 'expense_id' => $expense->id, 'optical_partner_payment_id' => $receipt->id, 'recorded_by' => $by->id,
            ]);
            $payment->forceFill(['confirmed_at' => $receipt->created_at])->save();
            AuditTrail::record('optical.link_receipt_booked', 'Added '.$link->clinic?->name.' receipt '.$receipt->receipt_number.' ('.currency().' '.number_format($row['amount'], 2).') to expenses',
                $expense, [], ['payment_id' => $payment->id], null, true);

            return $payment;
        });
    }

    /* ---------------------------------------------------------------- confirming (optical side) */

    /** Payments linked clinics recorded that wait for this optical centre. */
    public function waitingForOptical(?int $partnerId = null): Collection
    {
        return OpticalLinkPayment::forOptical($this->clinicId())->where('status', 'pending')
            ->when($partnerId, fn ($query) => $query->where('optical_partner_clinic_id', $partnerId))
            ->with('senderClinic', 'recorder')->orderBy('created_at')->get();
    }

    public static function waitingCount(): int
    {
        $clinicId = app(TenantContext::class)->clinicId();

        return $clinicId ? OpticalLinkPayment::forOptical($clinicId)->where('status', 'pending')->count() : 0;
    }

    /** Money arrived: book it on the partner account, settling the jobs the clinic named. */
    public function confirm(OpticalLinkPayment $payment, User $by): OpticalPartnerPayment
    {
        return DB::transaction(function () use ($payment, $by) {
            $payment = OpticalLinkPayment::forOptical($this->clinicId())->lockForUpdate()->findOrFail($payment->id);
            abort_unless($payment->status === 'pending', 422, 'This payment is no longer waiting for you.');
            $partner = OpticalPartnerClinic::findOrFail($payment->optical_partner_clinic_id);
            $account = app(OpticalPartnerAccountService::class);
            $open = $account->openOrders($partner)->keyBy('id');
            $owed = round($open->sum(fn ($order) => $account->balanceOf($order)), 2);
            if ((float) $payment->amount > $owed + 0.001) {
                throw ValidationException::withMessages(['payment' => 'This is more than the '.currency().' '.number_format($owed, 2).' '.$partner->name.' owes now. If you did not receive it all, choose "Not received" and say why.']);
            }
            // The clinic's split, by job; if a job was settled meanwhile, fall back to oldest first.
            $requests = OpticalOrderRequest::forOptical($this->clinicId())->whereIn('id', array_keys((array) $payment->allocations))->pluck('lens_order_id', 'id');
            $byOrder = [];
            foreach ((array) $payment->allocations as $requestId => $amount) {
                $orderId = (int) ($requests[(int) $requestId] ?? 0);
                if ($orderId) $byOrder[$orderId] = round(($byOrder[$orderId] ?? 0) + (float) $amount, 2);
            }
            $fits = $byOrder !== [] && abs(array_sum($byOrder) - (float) $payment->amount) < 0.001
                && collect($byOrder)->every(fn ($amount, $orderId) => $open->has($orderId) && $amount <= $account->balanceOf($open->get($orderId)) + 0.001);
            $receipt = $account->recordPayment($partner, (float) $payment->amount, $payment->payment_method,
                $payment->reference ?: $payment->voucher_number, trim('From '.$payment->senderClinic?->name.' · '.$payment->voucher_number.($payment->notes ? ' · '.$payment->notes : '')),
                $fits ? $byOrder : null);
            $payment->forceFill(['status' => 'confirmed', 'optical_partner_payment_id' => $receipt->id, 'confirmed_by' => $by->id, 'confirmed_at' => now()])->save();
            AuditTrail::record('optical.link_payment_confirmed', 'Confirmed '.currency().' '.number_format((float) $payment->amount, 2).' from '.$payment->senderClinic?->name.' ('.$payment->voucher_number.' → '.$receipt->receipt_number.')',
                $receipt, [], ['payment_id' => $payment->id], null, true);

            return $receipt;
        });
    }

    public function dispute(OpticalLinkPayment $payment, string $reason, User $by): void
    {
        app(ClinicAccessService::class)->assertWritable('optical');
        $reason = $this->clean($reason);
        if (! $reason) throw ValidationException::withMessages(['disputeReason' => 'Say what is wrong, so the clinic can check.']);
        DB::transaction(function () use ($payment, $reason, $by) {
            $payment = OpticalLinkPayment::forOptical($this->clinicId())->lockForUpdate()->findOrFail($payment->id);
            abort_unless($payment->status === 'pending', 422, 'This payment is no longer waiting for you.');
            $payment->forceFill(['status' => 'disputed', 'dispute_reason' => $reason, 'confirmed_by' => $by->id])->save();
            AuditTrail::record('optical.link_payment_disputed', 'Did not confirm '.currency().' '.number_format((float) $payment->amount, 2).' from '.$payment->senderClinic?->name.': '.$reason, null,
                [], ['payment_id' => $payment->id]);
        });
    }

    /* ---------------------------------------------------------------- helpers */

    private function bookExpense(OpticalPartnerClinic $link, float $amount, string $method, string $reference, string $description, ?string $notes, ?string $date = null): Expense
    {
        $category = ExpenseCategory::firstOrCreate(['name' => OpticalLinkPayment::EXPENSE_CATEGORY], [
            'section' => ExpenseCategory::OPERATING, 'is_active' => true,
            'description' => 'Spectacle jobs made for your patients by linked optical centres.',
        ]);
        $date ??= now()->toDateString();
        if ($lock = app(PeriodLockService::class)->covering(Expense::CLINIC, $date)) {
            throw ValidationException::withMessages(['payAmount' => 'The period '.$lock->from_date->format('d M Y').' – '.$lock->to_date->format('d M Y').' is locked. Unlock it on the Income Statement page first.']);
        }

        return Expense::create([
            'expense_category_id' => $category->id, 'expense_date' => $date, 'description' => $description,
            'payee' => $link->clinic?->name ?? $link->name, 'amount' => $amount,
            'payment_method' => array_key_exists($method, Expense::PAYMENT_METHODS) ? $method : 'bank_transfer',
            'reference' => $reference, 'notes' => $this->clean($notes), 'recorded_by' => auth()->id(), 'business_line' => Expense::CLINIC,
        ]);
    }

    /** @return array<int, float> */
    private function oldestFirst(Collection $open, float $amount): array
    {
        $plan = [];
        $left = $amount;
        foreach ($open as $requestId => $owed) {
            if ($left <= 0) break;
            $share = round(min($left, $owed), 2);
            $plan[$requestId] = $share;
            $left = round($left - $share, 2);
        }

        return $plan;
    }

    /** @return array<int, float> */
    private function manualPlan(Collection $open, float $amount, array $allocations): array
    {
        $plan = [];
        foreach ($allocations as $requestId => $value) {
            $share = round((float) $value, 2);
            if ($share <= 0) continue;
            if (! $open->has((int) $requestId)) throw ValidationException::withMessages(['allocations' => 'A selected job is not open on this account.']);
            if ($share > $open->get((int) $requestId) + 0.001) throw ValidationException::withMessages(['allocations' => 'You entered more than a job still owes.']);
            $plan[(int) $requestId] = $share;
        }
        if ($plan === [] || abs(array_sum($plan) - $amount) > 0.001) {
            throw ValidationException::withMessages(['allocations' => 'The amounts for each job must add up to the payment ('.currency().' '.number_format($amount, 2).').']);
        }

        return $plan;
    }

    private function dayClosed(): bool
    {
        return app(DayCloseService::class)->isClosed(now());
    }

    private function assertManager(User $user): void
    {
        abort_unless(\App\Support\Access\Access::allowsAny($user, ['optical_centres.manage', 'optical.manage']), 403);
    }

    private function clinicId(): int
    {
        $id = app(TenantContext::class)->clinicId();
        abort_unless($id, 403);

        return (int) $id;
    }

    private function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : mb_substr($text, 0, 1000);
    }
}
