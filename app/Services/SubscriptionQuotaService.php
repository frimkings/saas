<?php

namespace App\Services;

use App\Models\{Clinic,PatientDocument};
use Illuminate\Validation\ValidationException;

class SubscriptionQuotaService
{
    public function usage(Clinic $clinic): array
    {
        $subscription=app(SubscriptionService::class)->current($clinic);$plan=$subscription?->plan_snapshot??[];
        if (!app(ClinicAccessService::class)->hosted($clinic)) {
            $subscription = null;
            $plan = [];
        }
        return [
            'branches'=>['used'=>$clinic->branches()->where('is_active',true)->count(),'limit'=>$subscription?->branchLimit()],
            'users'=>['used'=>$clinic->users()->wherePivot('status','active')->count(),'limit'=>data_get($plan,'included_users')],
            'attendance_staff'=>['used'=>\App\Support\AttendancePlan::followed($clinic),'limit'=>\App\Support\AttendancePlan::staffLimit($clinic)],
            'storage_mb'=>['used'=>(float)PatientDocument::query()->where('clinic_id',$clinic->id)->sum('file_size')/1048576,'limit'=>$this->storageLimitMb($clinic,$plan)],
        ];
    }

    public function assertUserAvailable(Clinic $clinic): void { $this->assertAvailable($this->usage($clinic)['users'],1,'user'); }
    public function assertStorageAvailable(Clinic $clinic, int $incomingBytes): void
    {
        $quota = $this->usage($clinic)['storage_mb'];
        // A plan with no scan storage (0 MB) and no storage packs: say how to get it rather than "allowance reached".
        if ($quota['limit'] !== null && (float) $quota['limit'] <= 0) {
            throw ValidationException::withMessages(['subscription' => 'Scans and attachments come with the Standard plan and above, or with a scan storage pack. Ask your clinic\'s Super Admin to upgrade or add one under Subscription.']);
        }
        $this->assertAvailable($quota,$incomingBytes/1048576,'storage');
    }

    /** The plan's scan & attachment allowance plus any storage packs; null = no limit. */
    public function storageLimitMb(Clinic $clinic, ?array $plan = null): ?int
    {
        $plan ??= app(ClinicAccessService::class)->hosted($clinic) ? (app(SubscriptionService::class)->current($clinic)?->plan_snapshot ?? []) : [];
        $limit = data_get($plan, 'storage_limit_mb');

        return $limit === null ? null : (int) $limit + app(ClinicAddonService::class)->extraStorageMb($clinic->id);
    }

    private function assertAvailable(array $quota, float $increase, string $label): void
    {
        if($quota['limit']!==null && $quota['used']+$increase>(float)$quota['limit']) throw ValidationException::withMessages(['subscription'=>"The subscription {$label} allowance has been reached. Upgrade the clinic plan to continue."]);
    }
}
