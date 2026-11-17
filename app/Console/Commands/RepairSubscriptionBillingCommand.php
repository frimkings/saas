<?php

namespace App\Console\Commands;

use App\Models\Clinic;
use App\Models\ClinicAttendanceSize;
use App\Models\ClinicSubscription;
use App\Models\PlatformInvoice;
use App\Services\AttendanceSizing;
use App\Services\BillingAdjustmentService;
use App\Services\PlatformAuditService;
use App\Services\SubscriptionBillingService;
use App\Services\SubscriptionCollectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Tidies billing left by plan changes made before 17 Nov 2026, when a replaced subscription kept its
 * renewal invoices and a new one was not invoiced:
 *  1. unpaid renewal invoices of a replaced subscription, for days a later one covers, are voided;
 *  2. attendance add-ons bought before a package that includes them are ended, and their charge for
 *     the days the package covers is credited (or voided);
 *  3. current periods that have no invoice are listed, and invoiced only with --invoice-missing
 *     (some may have been paid outside the system).
 * Without --apply nothing is saved.
 */
class RepairSubscriptionBillingCommand extends Command
{
    protected $signature = 'subscriptions:repair-billing
        {--apply : Save the changes (without it, only show them)}
        {--invoice-missing : Also issue an invoice for each current period that has none}';
    protected $description = 'Void invoices of replaced subscriptions, end attendance add-ons a package includes, and list unbilled periods';

    public function handle(SubscriptionBillingService $billing, BillingAdjustmentService $adjust, AttendanceSizing $sizing): int
    {
        $apply = (bool) $this->option('apply');
        $this->info($apply ? 'Repairing billing.' : 'Dry run: nothing is saved. Add --apply to save.');
        DB::beginTransaction();
        try {
            $voided = $this->voidReplaced($billing, $adjust);
            $ended = $this->endCoveredAddons($sizing);
            $missing = $this->unbilledPeriods($billing, $apply && $this->option('invoice-missing'));
            if ($apply) app(SubscriptionCollectionService::class)->synchronize();

            $apply ? DB::commit() : DB::rollBack();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        if ($apply) {
            app(PlatformAuditService::class)->record('SUBSCRIPTION_BILLING_REPAIRED', null, [], compact('voided', 'ended', 'missing'), 'subscriptions:repair-billing');
        }
        $this->info("Voided {$voided} invoice(s); ended {$ended} add-on(s); {$missing} current period(s) without an invoice.");

        return self::SUCCESS;
    }

    private function voidReplaced(SubscriptionBillingService $billing, BillingAdjustmentService $adjust): int
    {
        $rows = [];
        $seen = [];
        $count = 0;
        // Each subscription replaces the ones before it, from the day it starts.
        ClinicSubscription::with('plan')->orderBy('id')->get()->groupBy('clinic_id')->each(function ($subs) use ($billing, $adjust, &$rows, &$seen, &$count) {
            foreach ($subs->slice(1) as $new) {
                foreach ($billing->replacedInvoices($new) as $old) {
                    if (isset($seen[$old->id])) continue;
                    $seen[$old->id] = true;
                    $paid = (float) $old->amount_paid > 0 || (float) $old->credited_amount > 0;
                    if (! $paid && $old->status !== 'void') {
                        $adjust->void($old, 'Replaced by '.($new->plan?->name ?? 'a later plan').' from '.$new->current_period_starts_at?->format('d M Y').' (subscription #'.$new->id.'); billing repair.', null);
                        $count++;
                    }
                    $rows[] = [$old->clinic?->name ?? $old->clinic_id, $old->number, $old->period_start?->format('d M Y').' – '.$old->period_end?->format('d M Y'),
                        number_format((float) $old->total, 2), $paid ? 'KEPT: has a payment, check it by hand' : 'voided', '#'.$new->id.' '.($new->plan?->name ?? '')];
                }
            }
        });
        $this->line('');
        $this->line('Invoices of replaced subscriptions:');
        $rows ? $this->table(['Clinic', 'Invoice', 'Period', 'Total', 'Action', 'Replaced by'], $rows) : $this->line('  none');

        return $count;
    }

    private function endCoveredAddons(AttendanceSizing $sizing): int
    {
        $count = 0;
        foreach (ClinicAttendanceSize::with('clinic')->whereNull('cancelled_at')->get() as $size) {
            $ended = $sizing->endCoveredAddons($size->clinic_id, $size->package, null, $size->starts_at);
            if ($ended) $this->line("Attendance: {$size->clinic?->name} — {$ended} add-on(s) ended, their charge from ".$size->starts_at->format('d M Y').' credited.');
            $count += $ended;
        }

        return $count;
    }

    private function unbilledPeriods(SubscriptionBillingService $billing, bool $issue): int
    {
        $rows = [];
        foreach (Clinic::with('currentSubscription.plan')->get() as $clinic) {
            $sub = $clinic->currentSubscription;
            if (! $sub || ! in_array($sub->status, ['active', 'overdue'], true) || ! $sub->current_period_starts_at || ! $sub->current_period_ends_at) continue;
            if ($clinic->deployment_mode === 'local' || (float) data_get($sub->pricing_snapshot, 'base_price', 0) <= 0) continue;
            $covered = PlatformInvoice::where('clinic_subscription_id', $sub->id)->where('source', 'renewal')->where('status', '!=', 'void')
                ->whereDate('period_end', '>', $sub->current_period_starts_at->toDateString())
                ->whereDate('period_start', '<=', $sub->current_period_ends_at->toDateString())->exists();
            if ($covered) continue;
            $invoice = $issue ? $billing->invoiceFor($sub, $sub->current_period_starts_at) : null;
            $rows[] = [$clinic->name, ($sub->plan?->name ?? '?').' ('.$sub->billing_interval.')', $sub->current_period_starts_at->format('d M Y').' – '.$sub->current_period_ends_at->format('d M Y'),
                $invoice ? 'invoiced '.$invoice->number.' GHS '.number_format((float) $invoice->total, 2) : 'no invoice'.($issue ? '' : ' (add --invoice-missing to issue one)')];
        }
        $this->line('');
        $this->line('Current periods with no invoice:');
        $rows ? $this->table(['Clinic', 'Plan', 'Period', 'Invoice'], $rows) : $this->line('  none');

        return count($rows);
    }
}
