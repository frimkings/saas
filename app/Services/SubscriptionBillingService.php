<?php

namespace App\Services;

use App\Models\{ClinicSubscription,PlatformInvoice,PlatformPayment,SubscriptionChange,SubscriptionPlan};
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubscriptionBillingService
{
    public function invoiceFor(ClinicSubscription $subscription, ?CarbonInterface $periodStart = null): PlatformInvoice
    {
        $subscription = app(SubscriptionLifecycleService::class)->ensureSnapshots($subscription->loadMissing(['plan','clinic']));
        // The next period starts the day after the current one ends (a period ending 23:59:59 on
        // the 28th renews from the 1st). The extra second keeps a midnight end on its own day.
        $start = $periodStart ? $periodStart->copy()->startOfDay()
            : ($subscription->current_period_ends_at?->copy()->addSecond()->startOfDay() ?? now()->startOfDay());
        if (!$periodStart && ($open = $this->openRenewalFrom($subscription, $start))) return $open;
        $end = $subscription->billing_interval === 'yearly' ? $start->copy()->addYear()->subDay() : $start->copy()->addMonth()->subDay();
        $key = "subscription:{$subscription->id}:{$start->toDateString()}:{$subscription->billing_interval}";
        $pricing = $subscription->pricing_snapshot ?? [];
        $base = (float) ($subscription->billing_interval === 'yearly' ? ($pricing['annual_price'] ?? 0) : ($pricing['base_price'] ?? 0));
        $limit = $subscription->branchLimit();
        $branches = $subscription->clinic->branches()->where('is_active', true)->count();
        $extras = $limit === null ? 0 : max(0, $branches - $limit);
        // Each charge is its own invoice line: the plan, extra branches, then the clinic's add-ons.
        $lines = [[($subscription->plan?->name ?? 'Subscription') . ' plan (' . $subscription->billing_interval . ')', round($base, 2), null]];
        // The branch price is monthly. A year of a branch is discounted like the plan's own year
        // (annual ÷ monthly price, e.g. 10 months for "2 months free"), or 12 months without a monthly price.
        $branchPrice = (float) ($pricing['additional_branch_price'] ?? 0);
        if ($subscription->billing_interval === 'yearly') {
            $monthly = (float) ($pricing['base_price'] ?? 0);
            $branchPrice *= $monthly > 0 ? (float) ($pricing['annual_price'] ?? 0) / $monthly : 12;
        }
        if ($extras > 0) $lines[] = [$extras . ' extra ' . Str::plural('branch', $extras), round($extras * $branchPrice, 2), null];
        // Attendance priced by staff size has a line of its own; a plan priced at nothing then needs no line.
        if ($attendance = app(AttendanceSizing::class)->renewalLine($subscription, $start, $end)) {
            if ((float) $lines[0][1] === 0.0) array_shift($lines);
            $lines[] = $attendance;
        }
        array_push($lines, ...app(ClinicAddonService::class)->renewalLines($subscription, $start, $end));
        $subtotal = round(array_sum(array_column($lines, 1)), 2);
        $tax = round($subtotal * ((float)($pricing['tax_rate'] ?? 0) / 100), 2);

        $invoice = PlatformInvoice::firstOrCreate(['idempotency_key'=>$key], [
            'number'=>'INV-'.now()->format('YmdHis').'-'.Str::upper(Str::random(5)),
            'clinic_id'=>$subscription->clinic_id,'clinic_subscription_id'=>$subscription->id,
            // Due on the grace day: staff are locked out after it (see config/subscriptions.php).
            'period_start'=>$start,'period_end'=>$end,'due_date'=>$start->copy(),
            'subtotal'=>$subtotal,'tax'=>$tax,'total'=>$subtotal+$tax,'amount_paid'=>0,
            'currency'=>$pricing['currency']??'GHS','status'=>'unpaid','source'=>'renewal',
            'notes'=>"Automated {$subscription->billing_interval} subscription renewal",
        ]);
        if ($invoice->wasRecentlyCreated) {
            foreach ($lines as $i => [$description, $amount, $addonId]) {
                $invoice->lines()->create(['description' => $description, 'amount' => $amount, 'clinic_addon_id' => $addonId, 'sort' => $i]);
            }
            app(BillingNotificationService::class)->invoiceIssued($invoice->load(['clinic','subscription']));
        }
        return $invoice;
    }

    /**
     * An open renewal invoice already issued for the coming period, including ones created before
     * periods began the day after the previous end (their start is one day earlier).
     */
    private function openRenewalFrom(ClinicSubscription $subscription, CarbonInterface $start): ?PlatformInvoice
    {
        return PlatformInvoice::where('clinic_subscription_id', $subscription->id)->where('source', 'renewal')
            ->whereNotIn('status', ['void', 'credited'])
            ->whereDateIndexed('period_start', '>=', $start->copy()->subDay()->toDateString())
            ->orderBy('period_start')->first();
    }

    public function generateDueInvoices(int $leadDays = 7): int
    {
        $count = 0;
        ClinicSubscription::query()->with(['plan','clinic'])->whereIn('status',['trial','active','overdue'])
            ->whereNotNull('current_period_ends_at')->where('current_period_ends_at','<=',now()->addDays($leadDays))
            ->chunkById(100,function($items)use(&$count){foreach($items as $item){$before=PlatformInvoice::count();$this->invoiceFor($item);$count += PlatformInvoice::count()>$before?1:0;}});
        return $count;
    }

    public function allocatePayment(PlatformInvoice $invoice, float $amount, string $method, ?string $reference, ?int $operatorId, ?string $idempotencyKey = null): PlatformPayment
    {
        return DB::transaction(function () use ($invoice,$amount,$method,$reference,$operatorId,$idempotencyKey) {
            $invoice = PlatformInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($idempotencyKey && ($existing=PlatformPayment::where('idempotency_key',$idempotencyKey)->first())) return $existing;
            if ($amount <= 0 || $amount > $invoice->balance()) throw ValidationException::withMessages(['paymentAmount'=>'Payment must be positive and cannot exceed the invoice balance.']);
            $payment=PlatformPayment::create(['platform_invoice_id'=>$invoice->id,'amount'=>$amount,'method'=>$method,'reference'=>$reference,'idempotency_key'=>$idempotencyKey,'status'=>'confirmed','paid_at'=>now(),'recorded_by'=>$operatorId]);
            $paid=round((float)$invoice->amount_paid+$amount,2);$fullyPaid=$paid >= (float)$invoice->total;
            $invoice->update(['amount_paid'=>$paid,'status'=>$fullyPaid?'paid':'partial','paid_at'=>$fullyPaid?now():null]);
            if($fullyPaid && $invoice->source==='renewal') $this->renewFromPaidInvoice($invoice->fresh('subscription'));
            // A yearly plan's free SMS (ClinicalSuitePlans::YEARLY_SMS) arrive with its paid renewal.
            if($fullyPaid && $invoice->source==='renewal') app(\App\Services\Messaging\SmsCreditService::class)->grantYearlyPlanSms($invoice->fresh('subscription.plan'));
            if($fullyPaid && $invoice->source==='sms_bundle') app(\App\Services\Messaging\SmsCreditService::class)->creditFromInvoice($invoice->fresh());
            app(BillingNotificationService::class)->paymentReceived($payment->load('invoice.clinic','invoice.subscription'));
            if($invoice->balance()<=0) \App\Models\SubscriptionCollectionCase::where('platform_invoice_id',$invoice->id)->whereIn('status',['open','promise'])->update(['status'=>'resolved','resolved_at'=>now()]);
            return $payment;
        });
    }

    public function renewFromPaidInvoice(PlatformInvoice $invoice): void
    {
        $subscription=$invoice->subscription;if(!$subscription)return;
        $start=$invoice->period_start->copy()->startOfDay();$end=$invoice->period_end->copy()->endOfDay();
        // Paid after expiry: the clinic was locked out, so the new period starts on the payment
        // date (clinic timezone) and runs a full term. The invoice is re-dated to the period it bought.
        $expiredAt=$subscription->status==='trial'?$subscription->trial_ends_at:$subscription->current_period_ends_at;
        if($expiredAt && $expiredAt->lte(now())){
            $tz=$subscription->clinic?->default_timezone?:config('app.timezone');
            $start=now($tz)->startOfDay();
            $end=($subscription->billing_interval==='yearly'?$start->copy()->addYear():$start->copy()->addMonth())->subDay()->endOfDay();
            $invoice->update(['period_start'=>$start->toDateString(),'period_end'=>$end->toDateString()]);
            $start=$start->setTimezone(config('app.timezone'));$end=$end->setTimezone(config('app.timezone'));
        }
        $subscription->update(['status'=>'active','current_period_starts_at'=>$start,'current_period_ends_at'=>$end,'grace_ends_at'=>null,'restricted_at'=>null,'suspended_at'=>null,'status_changed_at'=>now()]);
    }

    public function scheduleChange(ClinicSubscription $subscription, SubscriptionPlan $toPlan, string $interval, string $timing, string $reason, ?int $operatorId): SubscriptionChange
    {
        $effective=$timing==='immediate'?now():($subscription->current_period_ends_at??now());
        return DB::transaction(function()use($subscription,$toPlan,$interval,$timing,$reason,$operatorId,$effective){
            SubscriptionChange::where('clinic_id',$subscription->clinic_id)->where('status','scheduled')->update(['status'=>'cancelled','cancelled_at'=>now()]);
            $change=SubscriptionChange::create(['clinic_id'=>$subscription->clinic_id,'clinic_subscription_id'=>$subscription->id,'from_plan_id'=>$subscription->subscription_plan_id,'to_plan_id'=>$toPlan->id,'billing_interval'=>$interval,'timing'=>$timing,'status'=>'scheduled','effective_at'=>$effective,'reason'=>$reason,'requested_by'=>$operatorId]);
            if($timing==='immediate')$this->applyChange($change);
            return $change->refresh();
        });
    }

    public function applyChange(SubscriptionChange $change): ClinicSubscription
    {
        return DB::transaction(function()use($change){
            $change=SubscriptionChange::query()->lockForUpdate()->findOrFail($change->id);
            if($change->status==='applied')return $change->subscription;
            $old=$change->subscription;$plan=$change->toPlan;
            // There are no trials any more (2026-11-15): a clinic still on one (or whose trial has ended) starts paying
            // when its plan is changed, from today, and gets the first invoice at once, as a newly registered clinic does.
            // Keeping the trial and its end date left a clinic whose trial had ended locked out after its plan was changed.
            // Part-way through a paid period (an upgrade applied now) the dates already paid for are kept, and
            // only the difference for the days left is charged; starting a new month then left the paid one
            // unused and gave the dearer plan free until the next renewal.
            $midPeriod=in_array($old->status,['active','overdue'],true)&&$old->current_period_ends_at&&$old->current_period_ends_at->gt($change->effective_at);
            $period=match(true){
                $old->status==='trial'=>['status'=>'active','current_period_starts_at'=>now()->startOfDay(),'current_period_ends_at'=>($change->billing_interval==='yearly'?now()->startOfDay()->addYear():now()->startOfDay()->addMonth())->subDay()->endOfDay()],
                $midPeriod=>['status'=>$old->status,'current_period_starts_at'=>$old->current_period_starts_at,'current_period_ends_at'=>$old->current_period_ends_at],
                // A new paid period: from today (applied now) or the day after the old period ends, invoiced in handOver.
                default=>(function()use($change){$from=$change->timing==='period_end'?$change->effective_at->copy()->addSecond()->startOfDay():now()->startOfDay();
                    return ['status'=>'active','current_period_starts_at'=>$from,'current_period_ends_at'=>($change->billing_interval==='yearly'?$from->copy()->addYear():$from->copy()->addMonth())->subDay()->endOfDay()];})(),
            };
            $new=ClinicSubscription::create(['clinic_id'=>$old->clinic_id,'subscription_plan_id'=>$plan->id,'renewal_mode'=>$old->renewal_mode,'billing_interval'=>$change->billing_interval,'branch_limit_override'=>$old->branch_limit_override,'change_reason'=>$change->reason]+$period);
            // The old plan's invoices for days the new one covers are not owed twice (see handOver/keepCurrentPeriod).
            if($midPeriod)$this->keepCurrentPeriod($new,$change->requested_by);
            else $this->handOver($new,$change->requested_by);
            if($midPeriod){
                $difference=round((float)$plan->base_price-(float)data_get($old->pricing_snapshot,'base_price',$old->plan?->base_price??0),2);
                if($difference>0)app(\App\Services\ClinicAddonService::class)->chargeRestOfPeriod($new->clinic,$difference,
                    "Upgrade to {$plan->name}: difference from ".($old->plan?->name??'the previous plan'),"Plan change #{$change->id}: the difference for the rest of the period already paid.");
            }
            // The clinic's request for this plan is answered by the change itself.
            \App\Models\SubscriptionChangeRequest::where('clinic_id',$old->clinic_id)->where('status','pending')->where('requested_plan_id',$plan->id)
                ->update(['status'=>'approved','reviewed_by'=>$change->requested_by,'reviewed_at'=>now(),'review_notes'=>'Applied now.']);
            $change->update(['status'=>'applied','applied_at'=>now()]);return $new;
        });
    }

    /**
     * A new subscription takes over from the clinic's older ones (a plan change, a plan assigned by hand).
     * Their renewal invoices for days the new one now covers are not owed twice: unpaid ones are voided and
     * what was paid on them is credited to the new one's first invoice. The new period is then invoiced at
     * once, as for a newly registered clinic, so a plan change never gives a month free.
     * Returns the new period's invoice, or null when the new subscription is not one that pays (suspended…).
     */
    public function handOver(ClinicSubscription $new, ?int $operatorId = null): ?PlatformInvoice
    {
        $start = ($new->current_period_starts_at ?? now())->copy();
        $adjust = app(BillingAdjustmentService::class);
        $carried = 0.0;
        $paidOn = [];
        foreach ($this->replacedInvoices($new) as $old) {
            if ((float) $old->amount_paid <= 0 && (float) $old->credited_amount <= 0) {
                $adjust->void($old, 'Replaced by '.($new->plan?->name ?? 'a new plan').' from '.$start->format('d M Y').' (subscription #'.$new->id.').', $operatorId);
            } else {
                $carried += max(0, (float) $old->amount_paid - (float) $old->refunded_amount);
                $paidOn[] = $old->number;
            }
        }
        if (! in_array($new->status, ['active', 'overdue'], true)) return null;

        $invoice = $this->invoiceFor($new, $start);
        if ($carried > 0 && $invoice->wasRecentlyCreated && $invoice->balance() > 0) {
            $adjust->credit($invoice, round(min($carried, $invoice->balance()), 2), 'Already paid on '.implode(', ', $paidOn).' for the previous plan.', $operatorId);
        }

        return $invoice->refresh();
    }

    /**
     * An upgrade part-way through a paid period keeps that period: its invoice moves to the new subscription
     * (it is still owed, or already paid, as it was), and an unpaid renewal already issued for a later
     * period at the old price is voided, so the next renewal is at the new price.
     */
    public function keepCurrentPeriod(ClinicSubscription $new, ?int $operatorId = null): void
    {
        $periodEnd = $new->current_period_ends_at;
        foreach ($this->replacedInvoices($new) as $old) {
            $later = $periodEnd && $old->period_start && $old->period_start->gt($periodEnd);
            if ($later && (float) $old->amount_paid <= 0 && (float) $old->credited_amount <= 0) {
                app(BillingAdjustmentService::class)->void($old, 'Renewal at the old price, replaced by '.($new->plan?->name ?? 'a new plan').' (subscription #'.$new->id.').', $operatorId);
            } else {
                $old->update(['clinic_subscription_id' => $new->id]);
            }
        }
    }

    /** Open renewal invoices of the clinic's older subscriptions for days on or after the new one starts. */
    public function replacedInvoices(ClinicSubscription $new): \Illuminate\Support\Collection
    {
        $start = ($new->current_period_starts_at ?? now())->copy()->startOfDay();

        return PlatformInvoice::where('clinic_id', $new->clinic_id)->where('source', 'renewal')
            ->whereNotNull('clinic_subscription_id')->where('clinic_subscription_id', '<', $new->id)
            ->whereNotIn('status', ['void', 'credited'])
            ->whereDate('period_end', '>', $start->toDateString())
            ->orderBy('id')->get();
    }

    public function applyScheduledChanges(): int
    {
        $count=0;SubscriptionChange::with(['subscription','toPlan'])->where('status','scheduled')->where('effective_at','<=',now())->chunkById(100,function($items)use(&$count){foreach($items as $item){$this->applyChange($item);$count++;}});return $count;
    }
}
