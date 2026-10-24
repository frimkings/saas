<?php

namespace App\Console\Commands;

use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\PlatformInvoice;
use App\Models\SubscriptionChange;
use App\Services\PlatformAuditService;
use App\Support\PlanProduct;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Undoes test plan assignments. For each clinic it finds the latest earlier subscription on a
 * different product than the current one and adds a new current subscription copying it exactly
 * (plan, snapshots, interval, status, period dates). Nothing is deleted: the test rows stay as history.
 */
class RestorePreviousSubscriptionCommand extends Command
{
    protected $signature = 'subscriptions:restore-previous
        {clinics?* : Clinic ids (default: every clinic whose current plan is on --product)}
        {--product=both : The product of the test plan to undo (clinic, optical, both, attendance)}
        {--dry-run : Show what would change without changing anything}';
    protected $description = 'Put clinics back on the subscription they had before a test plan was assigned';

    public function handle(PlatformAuditService $audit): int
    {
        $product = (string) $this->option('product');
        if (! array_key_exists($product, PlanProduct::PRODUCTS)) {
            $this->error("Unknown product {$product}.");
            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry-run');

        $clinics = Clinic::query()->with('currentSubscription.plan')->orderBy('id');
        if ($ids = $this->argument('clinics')) $clinics->whereIn('id', $ids);

        $rows = [];
        $restored = 0;
        foreach ($clinics->get() as $clinic) {
            $current = $clinic->currentSubscription;
            if (! $current || self::productOf($current) !== $product) {
                if ($ids) $rows[] = [$clinic->id, $clinic->name, $current?->plan?->name ?? '-', '-', 'skipped: current plan is not '.$product];
                continue;
            }
            $history = ClinicSubscription::with('plan')->where('clinic_id', $clinic->id)->where('id', '<', $current->id)->orderByDesc('id')->get();
            $previous = $history->first(fn (ClinicSubscription $s) => self::productOf($s) !== $product);
            if (! $previous) {
                $rows[] = [$clinic->id, $clinic->name, $current->plan?->name ?? '-', '-', 'skipped: no earlier plan to go back to'];
                continue;
            }

            // The test subscriptions are the current one and any between it and the one restored.
            $testIds = $history->filter(fn ($s) => $s->id > $previous->id)->pluck('id')->push($current->id)->all();
            $invoices = PlatformInvoice::whereIn('clinic_subscription_id', $testIds)->whereNotIn('status', ['void', 'voided'])->get();
            $note = $invoices->isEmpty() ? '' : ' | invoices to check: '.$invoices->map(fn ($i) => "{$i->number} ({$i->status}, {$i->currency} {$i->total})")->implode(', ');

            $rows[] = [$clinic->id, $clinic->name, $current->plan?->name ?? data_get($current->plan_snapshot, 'name', '-'),
                ($previous->plan?->name ?? data_get($previous->plan_snapshot, 'name', '-')).' ('.$previous->billing_interval.', '.$previous->status.', to '.($previous->current_period_ends_at?->toDateString() ?? '-').')',
                ($dry ? 'would restore' : 'restored').$note];
            if ($dry) continue;

            DB::transaction(function () use ($clinic, $current, $previous, $audit) {
                SubscriptionChange::where('clinic_id', $clinic->id)->where('status', 'scheduled')->update(['status' => 'cancelled', 'cancelled_at' => now()]);
                $copy = $previous->only([
                    'subscription_plan_id', 'plan_snapshot', 'pricing_snapshot', 'feature_snapshot', 'status', 'renewal_mode', 'billing_interval',
                    'branch_limit_override', 'notes', 'trial_ends_at', 'current_period_starts_at', 'current_period_ends_at', 'grace_ends_at',
                    'cancelled_at', 'restricted_at', 'suspended_at',
                ]);
                $restored = ClinicSubscription::create([...$copy, 'clinic_id' => $clinic->id, 'change_reason' => "Restored subscription #{$previous->id} after a test plan"]);
                $audit->record('SUBSCRIPTION_RESTORED', $clinic->id, $current->toArray(), $restored->toArray(), "Test plan undone; back to subscription #{$previous->id}");
            });
            $restored++;
        }

        if (! $rows) {
            $this->info("No clinic is on a {$product} plan.");
            return self::SUCCESS;
        }
        $this->table(['Clinic', 'Name', 'Current (test) plan', 'Goes back to', 'Result'], $rows);
        $this->info($dry ? 'Dry run: nothing changed. Run again without --dry-run to apply.' : "{$restored} clinic(s) restored.");

        return self::SUCCESS;
    }

    private static function productOf(ClinicSubscription $subscription): string
    {
        return $subscription->plan?->product ?? PlanProduct::productOf($subscription->feature_snapshot);
    }
}
