<?php

namespace App\Support;

use App\Models\Appointments;
use App\Models\Clinic;
use App\Models\InsuranceClaim;
use App\Models\PatientDocument;
use App\Models\Sales;
use App\Services\SubscriptionService;

/**
 * Work a clinic still has on a feature its plan no longer includes (after a downgrade): open insurance
 * claims, scans already uploaded, appointments already booked, balances from before the change. The pages
 * for them stay open so the work can be finished, without starting new work on the feature.
 * Counts run for the clinic in the tenant context (models are clinic-scoped) and are kept for the request.
 */
final class PlanLeftovers
{
    /** Claims still waiting on the insurer. */
    public const OPEN_CLAIM_STATUSES = ['draft', 'submitted', 'approved', 'partially_approved'];

    /** Appointments that can still happen. */
    public const CLOSED_APPOINTMENT_STATUSES = ['Seen', 'Missed', 'Cancelled', 'Done'];

    private static array $counts = [];

    /** The features this applies to. */
    public const FEATURES = [Feature::INSURANCE, Feature::CLINICAL_DOCUMENTS, Feature::APPOINTMENTS, Feature::OUTSTANDING_BALANCES];

    /** Whether the clinic has work left on a feature it may no longer have. */
    public static function has(string $feature): bool
    {
        return in_array($feature, self::FEATURES, true) && self::count($feature) > 0;
    }

    public static function count(string $feature, ?Clinic $clinic = null): int
    {
        $clinic ??= app(\App\Support\Tenancy\TenantContext::class)->clinic();
        $key = ($clinic?->id ?? 0) . ':' . $feature;

        return self::$counts[$key] ??= match ($feature) {
            Feature::INSURANCE => InsuranceClaim::query()->whereIn('status', self::OPEN_CLAIM_STATUSES)->count(),
            Feature::CLINICAL_DOCUMENTS => PatientDocument::query()->count(),
            Feature::APPOINTMENTS => Appointments::query()->where('scheduled_at', '>=', now())->whereNotIn('status', self::CLOSED_APPOINTMENT_STATUSES)->count(),
            Feature::OUTSTANDING_BALANCES => self::balancesFromBefore($clinic),
            default => 0,
        };
    }

    /** Part-paid clinic sales from before the current subscription began (the change of plan). */
    private static function balancesFromBefore(?Clinic $clinic): int
    {
        $since = $clinic ? app(SubscriptionService::class)->current($clinic)?->created_at : null;

        return Sales::query()->where('business_line', 'clinic')->where('is_refunded', false)
            ->when($since, fn ($q) => $q->where('created_at', '<', $since))
            ->whereRaw(Sales::PATIENT_BALANCE_SQL . ' > 0')->count();
    }

    /** For tests and after a plan change in the same request. */
    public static function forget(): void
    {
        self::$counts = [];
    }
}
