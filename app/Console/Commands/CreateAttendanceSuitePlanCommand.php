<?php

namespace App\Console\Commands;

use App\Models\SubscriptionPlan;
use App\Services\PlanVersioningService;
use App\Services\PlatformAuditService;
use App\Support\PlanProduct;
use Illuminate\Console\Command;

/**
 * Creates the "Attendance Suite" plan for attendance-only clinics. It costs nothing on its own and
 * has no staff number or parts: the clinic's attendance size (Platform → clinic → Attendance by
 * staff size) gives the package, the staff limit and the price. Safe to run again.
 */
class CreateAttendanceSuitePlanCommand extends Command
{
    public const FAMILY = 'attendance-suite';

    protected $signature = 'pricing:attendance-suite';
    protected $description = 'Create the Attendance Suite plan for attendance-only clinics priced by staff size.';

    public function handle(PlanVersioningService $versions, PlatformAuditService $audit): int
    {
        if (SubscriptionPlan::where('family_code', self::FAMILY)->orWhere('code', self::FAMILY)->exists()) {
            $this->line('Attendance Suite: already exists, left as it is.');

            return self::SUCCESS;
        }
        $plan = $versions->save(null, [
            'name' => 'Attendance Suite', 'code' => self::FAMILY, 'product' => PlanProduct::ATTENDANCE, 'features' => PlanProduct::features(PlanProduct::ATTENDANCE, []),
            'included_branches' => null, 'included_users' => null, 'attendance_staff' => null, 'storage_limit_mb' => null,
            'base_price' => 0, 'annual_price' => 0, 'additional_branch_price' => 0,
            'currency' => 'GHS', 'billing_interval' => 'monthly', 'tax_rate' => 0, 'trial_days' => 14, 'grace_days' => 0, 'is_active' => true,
        ]);
        $audit->record('PLAN_CREATED', null, [], $plan->toArray());
        $this->info('Attendance Suite: created. Assign it to an attendance-only clinic, then set its package and staff under "Attendance by staff size".');

        return self::SUCCESS;
    }
}
