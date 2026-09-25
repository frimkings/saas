<?php

namespace App\Services;

use App\Models\{Clinic, ClinicSubscription, PlatformInvoice, SubscriptionApprovalRequest, SubscriptionReconciliationRun};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubscriptionReconciliationService
{
    public function run(?int $operatorId = null): SubscriptionReconciliationRun
    {
        return DB::transaction(function () use ($operatorId) {
            $run = SubscriptionReconciliationRun::create([
                'uuid' => (string) Str::uuid(), 'status' => 'running', 'run_by' => $operatorId,
                'completed_at' => now(),
            ]);
            $records = 0;
            $issue = function (string $code, string $severity, ?int $clinicId, ?string $type, ?int $id, string $message, string $remediation, array $evidence = []) use ($run): void {
                $run->issues()->create(compact('code', 'severity', 'message', 'remediation', 'evidence') + ['clinic_id' => $clinicId, 'entity_type' => $type, 'entity_id' => $id]);
            };

            $clinics = Clinic::with(['currentSubscription.plan', 'branches'])->get();
            foreach ($clinics as $clinic) {
                $records++;
                $subscription = $clinic->currentSubscription;
                if (! $subscription) {
                    $issue('MISSING_SUBSCRIPTION', 'critical', $clinic->id, Clinic::class, $clinic->id, 'Clinic has no current subscription.', 'Assign a plan or legacy subscription before permitting platform access.');
                    continue;
                }
                $records++;
                if (! $subscription->agreement()->exists()) {
                    $issue('MISSING_AGREEMENT', 'critical', $clinic->id, ClinicSubscription::class, $subscription->id, 'Subscription has no immutable agreement snapshot.', 'Generate and review the subscription agreement snapshot.');
                }
                if (! $subscription->plan_snapshot || ! $subscription->pricing_snapshot) {
                    $issue('MISSING_SNAPSHOT', 'critical', $clinic->id, ClinicSubscription::class, $subscription->id, 'Subscription pricing or plan snapshot is missing.', 'Rebuild snapshots from the agreed plan version and verify pricing.');
                }
                $limit = $subscription->branchLimit();
                $activeBranches = $clinic->branches->where('is_active', true)->count();
                if ($limit !== null && $activeBranches > $limit) {
                    $issue('BRANCH_LIMIT_EXCEEDED', 'warning', $clinic->id, ClinicSubscription::class, $subscription->id, "{$activeBranches} active branches exceed the allowance of {$limit}.", 'Increase the allowance or deactivate excess branches.', compact('activeBranches', 'limit'));
                }
                if (in_array($subscription->status, ['active', 'trial'], true) && $subscription->current_period_ends_at?->isPast()) {
                    $issue('PERIOD_EXPIRED', 'critical', $clinic->id, ClinicSubscription::class, $subscription->id, 'An active/trial subscription has an expired billing period.', 'Run lifecycle evaluation and verify renewal invoicing.');
                }
            }

            PlatformInvoice::with('payments')->chunkById(200, function ($invoices) use (&$records, $issue) {
                foreach ($invoices as $invoice) {
                    $records++;
                    if ($invoice->subscription && $invoice->subscription->clinic_id !== $invoice->clinic_id) {
                        $issue('INVOICE_CLINIC_MISMATCH', 'critical', $invoice->clinic_id, PlatformInvoice::class, $invoice->id, 'Invoice clinic differs from its subscription clinic.', 'Correct the invoice ownership before further allocation.');
                    }
                    $confirmed = round((float) $invoice->payments->where('status', 'confirmed')->sum('amount'), 2);
                    if ($invoice->payments->isNotEmpty() && abs($confirmed - (float) $invoice->amount_paid) > 0.009) {
                        $issue('PAYMENT_TOTAL_MISMATCH', 'critical', $invoice->clinic_id, PlatformInvoice::class, $invoice->id, 'Invoice paid amount does not match confirmed payment allocations.', 'Review payment allocations and reconcile the invoice aggregate.', ['invoice_amount_paid' => (float) $invoice->amount_paid, 'confirmed_payments' => $confirmed]);
                    }
                    if ($invoice->status === 'paid' && $invoice->balance() > 0.009) {
                        $issue('PAID_INVOICE_HAS_BALANCE', 'critical', $invoice->clinic_id, PlatformInvoice::class, $invoice->id, 'Paid invoice still has an outstanding balance.', 'Correct status or payment/adjustment totals.', ['balance' => $invoice->balance()]);
                    }
                    if ($invoice->status === 'void' && ! $invoice->voided_at) {
                        $issue('VOID_AUDIT_MISSING', 'warning', $invoice->clinic_id, PlatformInvoice::class, $invoice->id, 'Void invoice has no void timestamp.', 'Review its audit trail and record a valid void event.');
                    }
                }
            });

            SubscriptionApprovalRequest::where('status', 'pending')->where('expires_at', '<', now())->each(function ($approval) use (&$records, $issue) {
                $records++;
                $approval->update(['status' => 'expired']);
                $issue('EXPIRED_APPROVAL', 'warning', $approval->clinic_id, SubscriptionApprovalRequest::class, $approval->id, 'Pending sensitive action expired without review.', 'Submit a new request if the action is still required.');
            });

            $critical = $run->issues()->where('severity', 'critical')->count();
            $warnings = $run->issues()->where('severity', 'warning')->count();
            $run->update([
                'status' => $critical ? 'failed' : ($warnings ? 'warning' : 'passed'),
                'clinics_checked' => $clinics->count(), 'records_checked' => $records,
                'critical_issues' => $critical, 'warning_issues' => $warnings,
                'summary' => ['issues' => $critical + $warnings, 'critical' => $critical, 'warnings' => $warnings],
                'completed_at' => now(),
            ]);

            return $run->refresh()->load('issues');
        });
    }
}
