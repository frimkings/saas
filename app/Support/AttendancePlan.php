<?php

namespace App\Support;

use App\Models\AttendanceStaff;
use App\Models\Clinic;
use App\Services\ClinicAccessService;
use App\Services\SubscriptionService;
use App\Support\Tenancy\TenantContext;

/**
 * How many staff a clinic's plan lets it follow on attendance, set by the platform on each plan
 * ("Staff on attendance"; empty = unlimited), and which parts of attendance it has. Installs
 * that are not hosted have no limit.
 */
final class AttendancePlan
{
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

    /** Whether the clinic's plan or add-ons include the hospital tools (the clinic in the current context). */
    public static function hospital(?Clinic $clinic = null): bool
    {
        return \App\Services\LicenseService::has(Feature::ATTENDANCE_HOSPITAL);
    }

    /** Which parts of attendance the clinic has: [shifts, overtime, leave, payroll, phone, trends, hospital => bool]. */
    public static function parts(): array
    {
        $has = fn (string $feature) => \App\Services\LicenseService::has($feature);

        return ['shifts' => $has(Feature::ATTENDANCE_SHIFTS), 'overtime' => $has(Feature::ATTENDANCE_OVERTIME), 'leave' => $has(Feature::ATTENDANCE_LEAVE),
            'payroll' => $has(Feature::ATTENDANCE_PAYROLL), 'phone' => $has(Feature::ATTENDANCE_PHONE), 'trends' => $has(Feature::ATTENDANCE_TRENDS),
            'hospital' => $has(Feature::ATTENDANCE_HOSPITAL)];
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
