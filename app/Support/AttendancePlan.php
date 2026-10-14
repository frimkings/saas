<?php

namespace App\Support;

use App\Models\AttendanceStaff;
use App\Models\Clinic;
use App\Services\ClinicAccessService;
use App\Services\SubscriptionService;
use App\Support\Tenancy\TenantContext;

/**
 * How many staff a clinic's plan lets it follow on attendance, set by the platform on each plan
 * ("Staff on attendance"; empty = unlimited). The number also says what kind of package it is:
 * up to SMALL_CLINIC_MAX is a small clinic's; more, or unlimited, brings the hospital tools
 * (departments and their heads, minimum cover, rotation patterns, swaps, rest rules, staff lists
 * from a file). Installs that are not hosted have no limit.
 */
final class AttendancePlan
{
    /** The largest number of staff that still counts as a small clinic. */
    public static function smallClinicMax(): int
    {
        return (int) config('subscriptions.attendance_small_clinic_max', 30);
    }

    /** The plan's limit on people followed, or null for none. */
    public static function staffLimit(?Clinic $clinic = null): ?int
    {
        $clinic ??= app(TenantContext::class)->clinic();
        if (! $clinic || ! app(ClinicAccessService::class)->hosted($clinic)) {
            return null;
        }
        $subscription = app(SubscriptionService::class)->current($clinic);
        if (! $subscription) {
            return null;
        }
        // Subscriptions keep the allowance they started on; older ones read it from the plan.
        $limit = data_get($subscription->plan_snapshot ?? [], 'attendance_staff', $subscription->plan?->attendance_staff);

        return $limit === null ? null : (int) $limit;
    }

    /** Whether the clinic's package includes the hospital tools. */
    public static function hospital(?Clinic $clinic = null): bool
    {
        $limit = self::staffLimit($clinic);

        return $limit === null || $limit > self::smallClinicMax();
    }

    /** People followed across all the clinic's branches. */
    public static function followed(?Clinic $clinic = null): int
    {
        $clinic ??= app(TenantContext::class)->clinic();

        return $clinic ? AttendanceStaff::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('is_tracked', true)->count() : 0;
    }

    /** Whether the plan has room to follow so many more people. */
    public static function canFollow(int $more = 1, ?Clinic $clinic = null): bool
    {
        $limit = self::staffLimit($clinic);

        return $limit === null || self::followed($clinic) + $more <= $limit;
    }
}
