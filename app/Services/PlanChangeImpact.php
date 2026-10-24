<?php

namespace App\Services;

use App\Models\Clinic;
use App\Models\PatientDocument;
use App\Models\SubscriptionPlan;
use App\Support\Feature;
use App\Support\PlanLeftovers;
use App\Support\PlanProduct;
use App\Support\Tenancy\ActAsClinic;
use App\Services\PricingCatalogue;

/**
 * What moving a clinic to another plan would change, said before it happens: features it would lose
 * (add-ons it keeps paying for stay), limits it would be over, unfinished work on lost features (which
 * stays reachable to finish), and what applying the change now means for billing.
 *
 * @return array{lost: list<string>, over: list<string>, leftovers: list<string>, billing: ?string, downgrade: bool}
 */
class PlanChangeImpact
{
    public function for(Clinic $clinic, SubscriptionPlan $to, bool $now = false): array
    {
        $subscription = app(SubscriptionService::class)->current($clinic);
        $addons = app(ClinicAddonService::class)->activeFeatures($clinic->id);
        $current = array_values(array_unique([...($subscription?->feature_snapshot ?? []), ...$addons]));
        $target = array_values(array_unique([...($to->features ?? []), ...$addons]));
        $product = PlanProduct::productOf($to->features);

        $lostKeys = collect(array_keys(PlanProduct::extrasFor($product)))
            ->filter(fn ($feature) => PlanProduct::allows($current, $feature) && ! PlanProduct::allows($target, $feature))
            ->values()->all();

        $over = [];
        $users = $clinic->users()->wherePivot('status', 'active')->count();
        if ($to->included_users !== null && $users > $to->included_users) {
            $over[] = "{$users} active users; {$to->name} allows {$to->included_users}. They keep working, but no one new can be added.";
        }
        $packsMb = app(ClinicAddonService::class)->extraStorageMb($clinic->id);
        $usedMb = (int) ceil(PatientDocument::withoutGlobalScopes()->where('clinic_id', $clinic->id)->sum('file_size') / 1048576);
        if ($to->storage_limit_mb !== null && $usedMb > $to->storage_limit_mb + $packsMb) {
            $over[] = PricingCatalogue::storageLabel($usedMb) . ' of scans stored; ' . $to->name . ' has ' . PricingCatalogue::storageLabel($to->storage_limit_mb + $packsMb)
                . '. Nothing is deleted, but new uploads stop until a storage pack is added.';
        }
        $branches = $clinic->branches()->where('is_active', true)->count();
        if ($to->included_branches !== null && $branches > $to->included_branches) {
            $extra = $branches - $to->included_branches;
            $over[] = "{$branches} branches; {$to->name} includes {$to->included_branches}. The other {$extra} keep working and are billed at "
                . ($to->currency ?: 'GHS') . ' ' . number_format((float) $to->additional_branch_price, 2) . ' each a month from the next renewal.';
        }

        $leftovers = [];
        ActAsClinic::run($clinic, null, function () use ($lostKeys, $clinic, &$leftovers) {
            PlanLeftovers::forget();
            $say = [
                Feature::INSURANCE => fn ($n) => "{$n} open insurance " . ($n === 1 ? 'claim' : 'claims') . ': the insurance pages stay open to finish them and record insurer payments, but new sales are not billed to insurers.',
                Feature::CLINICAL_DOCUMENTS => fn ($n) => "{$n} " . ($n === 1 ? 'scan' : 'scans') . ' already uploaded: they stay viewable on the patient record, but no new uploads.',
                Feature::APPOINTMENTS => fn ($n) => "{$n} booked " . ($n === 1 ? 'appointment' : 'appointments') . ': they can still be managed or cancelled, and reminders still go out, but no new bookings.',
                Feature::OUTSTANDING_BALANCES => fn ($n) => "{$n} part-paid " . ($n === 1 ? 'bill' : 'bills') . ': Outstanding Balances stays open to collect them.',
            ];
            foreach ($say as $feature => $line) {
                if (in_array($feature, $lostKeys, true) && ($n = PlanLeftovers::count($feature, $clinic)) > 0) $leftovers[] = $line($n);
            }
            if (in_array(Feature::APPROVALS, $lostKeys, true) && ($n = \App\Support\ApprovalCounts::total()) > 0) {
                $leftovers[] = "{$n} " . ($n === 1 ? 'request' : 'requests') . ' waiting for approval: they can still be decided; new ones apply when asked for.';
            }
            PlanLeftovers::forget();
        });

        $oldPrice = (float) data_get($subscription?->pricing_snapshot, 'base_price', 0);
        $newPrice = (float) $to->base_price;
        $billing = null;
        if ($subscription?->current_period_ends_at) {
            $paidUntil = $subscription->current_period_ends_at;
            $billing = $now
                ? 'Starts today. ' . ($subscription->plan?->name ?? 'The current plan') . ' was paid until ' . $paidUntil->format('j M Y')
                    . '; no credit is issued for those days: ' . $to->name . '\'s first period starts today and is first billed at its renewal on ' . now()->addMonth()->format('j M Y') . '.'
                : 'Starts on ' . $paidUntil->format('j M Y') . ', when the period already paid for ends. Nothing is lost or owed for the current period.';
        }

        return [
            'lost' => array_map(fn ($feature) => PlanProduct::EXTRAS[$feature][0] ?? $feature, $lostKeys),
            'over' => $over,
            'leftovers' => $leftovers,
            'billing' => $billing,
            'downgrade' => $newPrice < $oldPrice,
        ];
    }
}
